<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AiProviderRepo;
use App\Services\AiClient;
use App\Services\AiToolbox;
use App\Services\AiUsageService;
use App\Services\AttachmentService;
use App\Services\ReceiptExtractor;
use App\Services\SettingsService;
use App\Support\Config;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AiController
{
    use JsonResponse;

    private const MAX_TOOL_ROUNDS = 6;
    private const MAX_HISTORY = 40;
    /** Only the most recent messages with photos are re-sent as images; older ones become a text note. */
    private const IMAGE_MESSAGES_SENT = 2;
    private const MAX_IMAGES_PER_MESSAGE = 10;
    /** Markdown/text files attached to a message are sent to the model as plain text. */
    private const MAX_DOCS_PER_MESSAGE = 5;
    private const MAX_DOC_CHARS = 60000;
    private const MAX_PROMPT_CHARS = 20000;

    private const ROLE_LABEL = ['admin' => 'ผู้ดูแลระบบ', 'manager' => 'ผู้จัดการร้าน', 'advisor' => 'ครูที่ปรึกษา', 'cashier' => 'พนักงานขาย'];

    private function repo(): AiProviderRepo
    {
        return new AiProviderRepo(Db::connection());
    }

    // ---------------- Chat (ai.use) ----------------

    public function models(Request $request, Response $response): Response
    {
        $rows = $this->repo()->enabledModels($request->getAttribute('user')['role']);
        return $this->json($response, ['data' => array_map(fn ($m) => [
            'id' => (int) $m['id'],
            'model_id' => $m['model_id'],
            'label' => $m['display_name'] ?: $m['model_id'],
            'supports_vision' => (bool) $m['supports_vision'],
            'supports_tools' => (bool) $m['supports_tools'],
            'price_in' => $m['price_in'] !== null ? (float) $m['price_in'] : null,
            'price_out' => $m['price_out'] !== null ? (float) $m['price_out'] : null,
            'provider_id' => (int) $m['provider_id'],
            'provider_name' => $m['provider_name'],
        ], $rows)]);
    }

    /**
     * Stateless tool-calling loop. The client keeps the conversation and sends
     * it back each turn; data-changing tool calls stop the loop and come back
     * as pending_actions, and run on the next request only if approved.
     */
    public function chat(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $repo = $this->repo();

        $db = Db::connection();
        $attachments = new AttachmentService($db);
        $history = $this->sanitizeHistory($body['messages'] ?? [], $attachments, (int) $user['id']);
        if (!$history) {
            return $this->error($response, 'ไม่มีข้อความ', 422);
        }
        $approvals = is_array($body['approvals'] ?? null) ? $body['approvals'] : [];

        $userMessages = array_values(array_filter($history, fn ($m) => $m['role'] === 'user'));
        $lastUser = end($userMessages) ?: [];
        $withPhotos = array_values(array_filter($userMessages, fn ($m) => !empty($m['attachments'])));
        $latestPhotos = $withPhotos ? end($withPhotos)['attachments'] : [];

        // "auto": photos in this turn → cheapest vision model; anything else → cheapest model overall.
        $auto = ($body['model'] ?? 'auto') === 'auto';
        $needVision = !$approvals && !empty($lastUser['attachments']) && end($history)['role'] === 'user';
        $resolved = $auto ? $repo->pickCheapest($needVision, $user['role']) : $repo->resolveEnabledModel((int) $body['model'], $user['role']);
        if (!$resolved) {
            return $this->error($response, $auto && $needVision
                ? 'ยังไม่มีโมเดลที่อ่านรูปได้ — ผู้ดูแลระบบต้องเปิดโมเดลที่อ่านรูปได้และติ๊ก "อ่านรูป" ในหน้าตั้งค่าผู้ช่วย AI'
                : 'ไม่พบโมเดลที่เลือก หรือโมเดลถูกปิดใช้งาน / ไม่ได้เปิดให้บทบาทของคุณใช้', 422);
        }
        [$provider, $model] = $resolved;

        $vision = (bool) $model['supports_vision'];
        if (!$vision && $needVision) {
            return $this->error($response, 'โมเดลที่เลือกอ่านรูปภาพไม่ได้ กรุณาเลือกโมเดลที่รองรับรูปภาพ (มีไอคอน 🖼) แล้วส่งใหม่', 422);
        }

        $toolbox = new AiToolbox($db, $user, $request->getServerParams()['REMOTE_ADDR'] ?? null);
        $toolbox->setPhotos($latestPhotos);
        $tools = $model['supports_tools'] ? $toolbox->schemas() : [];
        // Receipt photos or an attached receipt file: the model writes every line into a draft, which takes a while.
        $longTask = $needVision || (end($history)['role'] === 'user' && !empty(end($history)['documents']));
        $client = new AiClient($provider['base_url'], $repo->apiKey($provider), $repo->extraHeaders($provider), $longTask ? 240 : 90);
        if ($longTask) {
            set_time_limit(300);
        }
        $imageCache = [];

        // A new message with photos is billed as a receipt read; follow-ups (approvals) are not.
        $lastMessage = end($history);
        $newPhotoTurn = !$approvals && $lastMessage['role'] === 'user' && !empty($lastMessage['attachments']);
        $newDocs = !$approvals && $lastMessage['role'] === 'user' ? ($lastMessage['documents'] ?? []) : [];
        $usageService = new AiUsageService($db, new SettingsService($db));
        // Billing: receipt photos > attached .md / long pasted markdown > general.
        $category = match (true) {
            $newPhotoTurn => 'receipt',
            (bool) $newDocs, !$approvals && $usageService->isLargeMarkdown((string) ($lastUser['content'] ?? '')) => 'markdown',
            default => 'general',
        };
        $usage = [
            'user_id' => (int) $user['id'],
            'prompt' => $approvals
                ? '[ตอบการยืนยัน] ' . $this->describeDecisions($history, $approvals)
                : (string) ($lastUser['content'] ?? ''),
            'category' => $category,
            'image_count' => $newPhotoTurn ? count($lastMessage['attachments']) : 0,
            'documents' => array_column($newDocs, 'name'),
            'model_row_id' => (int) $model['id'],
            'model_name' => $model['model_id'],
            'provider_name' => $provider['name'],
            'started' => microtime(true),
        ];

        // Answer any tool calls left open by the previous turn (approved/rejected actions).
        $history = $this->resolveOpenToolCalls($history, $toolbox, $approvals);
        $firstNew = count($history);

        // Drafts from the step-by-step .md import: report the save result directly
        // instead of asking a (possibly slow) model to restate it.
        if ($approvals && array_filter(array_keys($approvals), fn ($id) => str_starts_with((string) $id, 'imp_')) === array_keys($approvals)) {
            $history[] = ['role' => 'assistant', 'content' => $this->importResultText($history, $approvals)];
            return $this->json($response, [
                'messages' => $history,
                'pending_actions' => [],
                'client_actions' => $toolbox->takeClientActions(),
            ]);
        }

        $toolNames = array_column(array_column($tools, 'function'), 'name');
        // A receipt turn must end in a create_purchase draft; weaker models sometimes
        // just describe the purchase ("บันทึกแล้ว") without calling the tool.
        $expectPurchase = in_array('create_purchase', $toolNames, true) && ($newPhotoTurn || $newDocs);
        $nudged = false;
        $pending = [];
        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $messages = array_merge(
                    [['role' => 'system', 'content' => $this->systemPrompt($user, $toolbox, (bool) $tools, $vision)]],
                    $this->toModelMessages($history, $vision, $attachments, $imageCache)
                );
                if ($nudged) {
                    $messages[] = ['role' => 'system', 'content' => 'คุณยังไม่ได้เรียกเครื่องมือ create_purchase ใบซื้อจึงยังไม่ถูกบันทึก'
                        . ' ห้ามตอบว่าบันทึกแล้ว ให้เรียก create_purchase พร้อมรายการสินค้าทั้งหมดจากใบเสร็จตอนนี้เลย ระบบจะแสดงปุ่มให้ผู้ใช้ยืนยันเอง'];
                }
                $reply = $this->normalizeAssistant($client->chat($model['model_id'], $messages, $tools), $toolNames);
                $reply['model'] = $model['display_name'] ?: $model['model_id'];

                if (empty($reply['tool_calls']) && $expectPurchase && !$nudged && !$this->calledTool($history, $firstNew, 'create_purchase')) {
                    $nudged = true; // retry once; the text-only reply is dropped
                    continue;
                }
                $history[] = $reply;

                if (empty($reply['tool_calls'])) {
                    break;
                }
                foreach ($reply['tool_calls'] as $call) {
                    $name = $call['function']['name'];
                    $args = $this->decodeArgs($call['function']['arguments']);
                    if ($toolbox->needsConfirmation($name)) {
                        $editable = $toolbox->editableInForm($name);
                        $pending[] = [
                            'id' => $call['id'],
                            'name' => $name,
                            'summary' => $toolbox->describe($name, $args),
                            'details' => $toolbox->details($name, $args),
                            'editable' => $editable,
                            'draft' => $editable ? $toolbox->formDraft($name, $args) : null,
                        ];
                        continue;
                    }
                    $history[] = $this->toolResult($call['id'], $toolbox->execute($name, $args));
                }
                if ($pending) {
                    break;
                }
            }
        } catch (\RuntimeException $e) {
            $message = self::friendlyError($e);
            $this->logUsage($db, $usage, array_slice($history, $firstNew), $message);
            // Not 5xx: Cloudflare/the web server replace 5xx bodies with their own page,
            // and the user would only see "เกิดข้อผิดพลาด (502)" instead of this message.
            return $this->json($response, [
                'error' => $message,
                'messages' => $history,
                'client_actions' => $toolbox->takeClientActions(),
            ], 422);
        }

        $this->logUsage($db, $usage, array_slice($history, $firstNew), null);

        return $this->json($response, [
            'messages' => $history,
            'model_used' => ['id' => (int) $model['id'], 'label' => $model['display_name'] ?: $model['model_id'], 'auto' => $auto],
            'pending_actions' => $pending,
            'client_actions' => $toolbox->takeClientActions(),
        ]);
    }

    /** A missing table means the code was deployed before its migration was run. */
    private static function friendlyError(\RuntimeException $e): string
    {
        if ($e instanceof \PDOException && ($e->errorInfo[0] ?? $e->getCode()) === '42S02') {
            return 'ฐานข้อมูลยังไม่ได้อัปเดตตามโปรแกรมเวอร์ชันใหม่ — ผู้ดูแลระบบต้องรัน Migration ที่ค้างอยู่ (เมนู ระบบ > Migrations) ก่อน';
        }
        return $e->getMessage();
    }

    /** Records one chat request with the category decided from what the user sent. */
    private function logUsage(\PDO $db, array $usage, array $newMessages, ?string $error): void
    {
        $service = new AiUsageService($db, new SettingsService($db));
        $texts = [];
        $tools = [];
        foreach ($newMessages as $m) {
            if ($m['role'] !== 'assistant') {
                continue;
            }
            if ($m['content'] !== '') {
                $texts[] = $m['content'];
            }
            foreach ($m['tool_calls'] ?? [] as $c) {
                $tools[] = $c['function']['name'];
            }
        }
        try {
            $service->log([
                'user_id' => $usage['user_id'],
                'channel' => 'chat',
                'category' => $usage['category'],
                'prompt' => $usage['prompt']
                    . ($usage['image_count'] ? " [แนบรูป {$usage['image_count']} รูป]" : '')
                    . ($usage['documents'] ? ' [แนบไฟล์ ' . implode(', ', $usage['documents']) . ']' : ''),
                'model_row_id' => $usage['model_row_id'],
                'model_name' => $usage['model_name'],
                'provider_name' => $usage['provider_name'],
                'image_count' => $usage['image_count'],
                'response_chars' => mb_strlen(implode("\n", $texts)),
                'tool_calls' => $tools,
                'status' => $error === null ? 'ok' : 'error',
                'error_message' => $error,
                'duration_ms' => (int) round((microtime(true) - $usage['started']) * 1000),
            ]);
        } catch (\Throwable $e) {
            // Usage logging must never break the chat itself.
            error_log('AI usage log failed: ' . $e->getMessage());
        }
    }

    /** "อนุมัติ: บันทึกใบซื้อ, ยกเลิก: เบิกเงิน" style summary of the user's decisions. */
    private function describeDecisions(array $history, array $approvals): string
    {
        $names = [];
        foreach ($history as $m) {
            foreach ($m['tool_calls'] ?? [] as $c) {
                $names[$c['id']] = $c['function']['name'];
            }
        }
        $out = [];
        foreach ($approvals as $id => $decision) {
            $label = $decision === true ? 'อนุมัติ' : ($decision === 'edit' ? 'แก้ไขในฟอร์ม' : 'ยกเลิก');
            $out[] = $label . ': ' . ($names[$id] ?? $id);
        }
        return implode(', ', $out);
    }

    private function importResultText(array $history, array $approvals): string
    {
        $lines = [];
        foreach ($history as $m) {
            if ($m['role'] !== 'tool' || !isset($approvals[$m['tool_call_id']])) {
                continue;
            }
            $r = json_decode($m['content'], true) ?: [];
            if (!empty($r['ok'])) {
                $lines[] = sprintf(
                    '✓ บันทึกใบซื้อ **%s** แล้ว ยอด ฿%s — สินค้าใหม่ %d รายการ, เติมสต๊อก %d รายการ%s',
                    $r['doc_no'], number_format((float) $r['total'], 2), $r['new_products'], $r['restocked_products'],
                    $r['business_supplies'] ? ", ใช้ในกิจการ {$r['business_supplies']} รายการ" : ''
                );
            } elseif (!empty($r['error'])) {
                $lines[] = '⚠ บันทึกไม่สำเร็จ: ' . $r['error'];
            } else {
                $lines[] = $approvals[$m['tool_call_id']] === 'edit'
                    ? 'เปิดร่างใบซื้อในหน้า "ซื้อสินค้าเข้าร้าน" แล้ว ตรวจแก้และกดบันทึกที่หน้านั้นได้เลย'
                    : 'ยกเลิกแล้ว ยังไม่ได้บันทึกใบซื้อ';
            }
        }
        return implode("\n", $lines) ?: 'ไม่มีรายการที่ต้องดำเนินการ';
    }

    private function resolveOpenToolCalls(array $history, AiToolbox $toolbox, array $approvals): array
    {
        $lastAssistant = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'assistant') {
                $lastAssistant = $i;
                break;
            }
        }
        if ($lastAssistant === null || empty($history[$lastAssistant]['tool_calls'])) {
            return $history;
        }
        $answered = [];
        foreach (array_slice($history, $lastAssistant + 1) as $m) {
            if ($m['role'] === 'tool') {
                $answered[$m['tool_call_id']] = true;
            }
        }
        // A user message typed after unanswered calls must come after their results.
        $tail = array_values(array_filter(array_slice($history, $lastAssistant + 1), fn ($m) => $m['role'] !== 'tool'));
        $head = array_slice($history, 0, $lastAssistant + 1);
        foreach (array_slice($history, $lastAssistant + 1) as $m) {
            if ($m['role'] === 'tool') {
                $head[] = $m;
            }
        }
        foreach ($history[$lastAssistant]['tool_calls'] as $call) {
            if (isset($answered[$call['id']])) {
                continue;
            }
            $name = $call['function']['name'];
            $args = $this->decodeArgs($call['function']['arguments']);
            $decision = $approvals[$call['id']] ?? null;
            if ($toolbox->needsConfirmation($name) && $decision === 'edit') {
                $result = ['cancelled' => true, 'message' => 'ผู้ใช้นำข้อมูลไปตรวจแก้และบันทึกเองในหน้าฟอร์มแล้ว ไม่ต้องบันทึกซ้ำ'];
            } elseif ($toolbox->needsConfirmation($name) && $decision !== true) {
                $result = ['cancelled' => true, 'message' => 'ผู้ใช้ไม่อนุมัติให้ดำเนินการนี้'];
            } else {
                $result = $toolbox->execute($name, $args);
            }
            $head[] = $this->toolResult($call['id'], $result);
        }
        return array_merge($head, $tail);
    }

    private function systemPrompt(array $user, AiToolbox $toolbox, bool $hasTools, bool $vision): string
    {
        $appName = (string) Config::get('app.name', 'ระบบฝากขาย ร้านค้าสหการ');
        $role = self::ROLE_LABEL[$user['role']] ?? $user['role'];
        $perms = implode(', ', $toolbox->permissions());
        $now = date('Y-m-d H:i');
        $toolsNote = $hasTools
            ? "- ใช้เครื่องมือ (tools) เพื่อดึงข้อมูลจริงจากระบบทุกครั้ง ห้ามเดาตัวเลขหรือข้อมูลเอง\n"
              . "- เมื่อต้องเพิ่ม/แก้ไขข้อมูล ให้เรียกเครื่องมือได้เลย ระบบจะแสดงปุ่มให้ผู้ใช้ยืนยันเอง ถ้าข้อมูลที่จำเป็นยังไม่ครบให้ถามผู้ใช้ก่อน\n"
              . "- หากผู้ใช้ต้องการให้ทำสิ่งที่ไม่มีเครื่องมือรองรับ (เช่น ขายสินค้า รับฝาก ตรวจนับ) ให้แนะนำขั้นตอนและเปิดหน้าที่เกี่ยวข้องด้วย navigate_to\n"
            : "- โมเดลนี้ไม่รองรับการเรียกเครื่องมือ จึงตอบได้เฉพาะคำแนะนำทั่วไป ไม่สามารถเข้าถึงข้อมูลในระบบได้\n";
        if ($hasTools && in_array('purchase.create', $toolbox->permissions(), true)) {
            $toolsNote .= "- เมื่อผู้ใช้แนบรูปใบเสร็จ" . ($vision ? '' : ' (โมเดลนี้อ่านรูปไม่ได้ ใช้ได้เฉพาะข้อความ)') . " หรือข้อความใบเสร็จการซื้อสินค้าเข้าร้าน ให้อ่านตามหลักนี้:\n"
                . ReceiptExtractor::INSTRUCTIONS . "\n"
                . "  ถ้าผู้ใช้แนบไฟล์ .md หรือวางข้อความของใบเสร็จมา ให้ใช้หลักเดียวกันกับข้อความนั้น\n"
                . "  จากนั้นสรุปสั้น ๆ (ร้านที่ซื้อ จำนวนรายการ ยอดรวม และตรงกับยอดสุทธิหรือไม่) แล้วเรียก create_purchase พร้อมรายการทั้งหมด"
                . " ราคาในใบเสร็จคือราคาทุน ไม่ต้องใส่ราคาขายถ้าผู้ใช้ไม่ได้บอก (ระบบคิดให้) รูปที่แนบจะถูกเก็บคู่กับใบซื้อโดยอัตโนมัติ\n";
        }

        return <<<TXT
        คุณคือผู้ช่วย AI ของ "{$appName}" ซึ่งเป็นระบบบัญชีร้านค้าฝากขายของสถานศึกษา (รับฝากสินค้า ขาย คิดค่าฝากขาย สมุดบัญชีผู้ฝาก เบิกจ่ายเงิน ตรวจนับสต๊อก รายงาน)
        ผู้ใช้ปัจจุบัน: {$user['full_name']} (บทบาท: {$role})
        สิทธิ์ของผู้ใช้: {$perms}
        วันเวลาปัจจุบัน: {$now}

        แนวทาง:
        - ตอบเป็นภาษาไทย สุภาพ กระชับ ใช้ Markdown ได้ (ตาราง รายการ ตัวหนา)
        - ทำได้เฉพาะสิ่งที่อยู่ในสิทธิ์ของผู้ใช้เท่านั้น ถ้าไม่มีสิทธิ์ให้แจ้งตรง ๆ
        {$toolsNote}- จำนวนเงินแสดงเป็นบาท (฿) ทศนิยม 2 ตำแหน่ง
        TXT;
    }

    /**
     * Builds the provider payload: photos are inlined as image parts for the
     * latest messages that have them (vision models only); the token lists
     * kept in the client-side history never reach the provider.
     */
    private function toModelMessages(array $history, bool $vision, AttachmentService $attachments, array &$cache): array
    {
        $withPhotos = array_keys(array_filter($history, fn ($m) => $m['role'] === 'user' && !empty($m['attachments'])));
        $inline = array_flip(array_slice($withPhotos, -self::IMAGE_MESSAGES_SENT));
        $out = [];
        foreach ($history as $i => $m) {
            if ($m['role'] === 'user' && !empty($m['documents'])) {
                $m['content'] = ($m['content'] !== '' ? $m['content'] : 'สรุป/นำเข้าข้อมูลจากไฟล์ที่แนบ') . $this->documentsText($m['documents']);
            }
            unset($m['documents'], $m['model']);
            if ($m['role'] !== 'user' || empty($m['attachments'])) {
                unset($m['attachments']);
                $out[] = $m;
                continue;
            }
            $count = count($m['attachments']);
            $text = $m['content'] !== '' ? $m['content'] : 'นำเข้าข้อมูลการซื้อสินค้าจากใบเสร็จนี้';
            if (!$vision || !isset($inline[$i])) {
                $out[] = ['role' => 'user', 'content' => $text . "\n[แนบรูปใบเสร็จ {$count} รูป — ไม่ได้ส่งรูปซ้ำในรอบนี้]"];
                continue;
            }
            $note = $count > 1 ? "\n(แนบ {$count} รูป เป็นใบเสร็จใบเดียวกัน เรียงจากบนลงล่าง)" : '';
            $parts = [['type' => 'text', 'text' => $text . $note]];
            foreach ($m['attachments'] as $token) {
                if (!array_key_exists($token, $cache)) {
                    $row = $attachments->findByToken($token);
                    $cache[$token] = $row ? $attachments->dataUrl($row) : null;
                }
                if ($cache[$token]) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $cache[$token]]];
                }
            }
            $out[] = ['role' => 'user', 'content' => $parts];
        }
        return $out;
    }

    private function documentsText(array $docs): string
    {
        $out = '';
        foreach ($docs as $d) {
            if (!empty($d['imported'])) {
                $out .= "\n[ไฟล์แนบ: {$d['name']} — นำเข้าเป็นร่างใบซื้อแล้ว]";
                continue;
            }
            $out .="\n\n--- ไฟล์แนบ: {$d['name']} ---\n{$d['content']}\n--- จบไฟล์ {$d['name']} ---";
        }
        return $out;
    }

    /** Keeps only well-formed user/assistant/tool messages from the client. */
    private function sanitizeHistory(mixed $messages, AttachmentService $attachments, int $userId): array
    {
        if (!is_array($messages)) {
            return [];
        }
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = $m['role'] ?? '';
            if ($role === 'user') {
                $text = trim((string) ($m['content'] ?? ''));
                // Only photos this user uploaded may be referenced.
                $tokens = array_column(
                    $attachments->ownedBy(array_slice((array) ($m['attachments'] ?? []), 0, self::MAX_IMAGES_PER_MESSAGE), $userId),
                    'token'
                );
                $docs = [];
                foreach (array_slice((array) ($m['documents'] ?? []), 0, self::MAX_DOCS_PER_MESSAGE) as $d) {
                    if (!is_array($d) || trim((string) ($d['content'] ?? '')) === '') {
                        continue;
                    }
                    $name = preg_replace('/[^\p{L}\p{N} ._()-]/u', '', (string) ($d['name'] ?? 'file.md')) ?: 'file.md';
                    $docs[] = ['name' => mb_substr($name, 0, 120), 'content' => mb_substr((string) $d['content'], 0, self::MAX_DOC_CHARS)]
                        + (!empty($d['imported']) ? ['imported' => true] : []);
                }
                if ($text !== '' || $tokens || $docs) {
                    $msg = ['role' => 'user', 'content' => mb_substr($text, 0, self::MAX_PROMPT_CHARS)];
                    if ($tokens) {
                        $msg['attachments'] = $tokens;
                    }
                    if ($docs) {
                        $msg['documents'] = $docs;
                    }
                    $out[] = $msg;
                }
            } elseif ($role === 'assistant') {
                $out[] = $this->normalizeAssistant($m);
            } elseif ($role === 'tool' && !empty($m['tool_call_id'])) {
                $out[] = ['role' => 'tool', 'tool_call_id' => (string) $m['tool_call_id'], 'content' => mb_substr((string) ($m['content'] ?? ''), 0, 30000)];
            }
        }
        $out = array_slice($out, -self::MAX_HISTORY);
        // Never start on an orphaned tool result.
        while ($out && $out[0]['role'] === 'tool') {
            array_shift($out);
        }
        return $out;
    }

    private function calledTool(array $history, int $from, string $name): bool
    {
        foreach (array_slice($history, $from) as $m) {
            foreach ($m['tool_calls'] ?? [] as $c) {
                if ($c['function']['name'] === $name) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param string[] $toolNames tools offered this turn; used to recover calls a model wrote as text */
    private function normalizeAssistant(array $m, array $toolNames = []): array
    {
        $content = $m['content'] ?? '';
        if (is_array($content)) { // some servers return content parts
            $content = implode('', array_map(fn ($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $content));
        }
        $content = (string) $content;
        // Reasoning models served without a reasoning parser leak <think>…</think> (sometimes only the closing tag).
        $content = preg_replace('~<think>.*?</think>~su', '', $content) ?? $content;
        if (($pos = mb_strrpos($content, '</think>')) !== false) {
            $content = mb_substr($content, $pos + mb_strlen('</think>'));
        }
        // Some servers don't parse tool calls and pass them through as <tool_call>{json}</tool_call> text.
        if (empty($m['tool_calls']) && $toolNames && preg_match_all('~<tool_call>\s*(\{.*?\})\s*</tool_call>~su', $content, $found)) {
            foreach ($found[1] as $json) {
                $call = json_decode($json, true);
                if (is_array($call) && in_array($call['name'] ?? null, $toolNames, true)) {
                    $m['tool_calls'][] = ['function' => ['name' => $call['name'], 'arguments' => $call['arguments'] ?? $call['parameters'] ?? []]];
                }
            }
            if (!empty($m['tool_calls'])) {
                $content = preg_replace('~<tool_call>.*?</tool_call>~su', '', $content) ?? $content;
            }
        }
        $msg = ['role' => 'assistant', 'content' => trim($content)];
        if (isset($m['model']) && is_string($m['model'])) {
            $msg['model'] = mb_substr($m['model'], 0, 100); // UI label only; stripped before the provider call
        }
        $calls = [];
        foreach ((array) ($m['tool_calls'] ?? []) as $c) {
            $name = (string) ($c['function']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $args = $c['function']['arguments'] ?? '{}';
            $calls[] = [
                'id' => (string) (($c['id'] ?? '') ?: 'call_' . bin2hex(random_bytes(6))),
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => is_string($args) ? $args : json_encode($args, JSON_UNESCAPED_UNICODE)],
            ];
        }
        if ($calls) {
            $msg['tool_calls'] = $calls;
        }
        return $msg;
    }

    private function decodeArgs(string $json): array
    {
        $args = json_decode($json, true);
        return is_array($args) ? $args : [];
    }

    private function toolResult(string $callId, array $result): array
    {
        return ['role' => 'tool', 'tool_call_id' => $callId, 'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    // ---------------- Provider admin (ai.manage) ----------------

    public function providers(Request $request, Response $response): Response
    {
        $presets = [];
        foreach (AiProviderRepo::PRESETS as $key => $p) {
            $presets[] = ['key' => $key] + $p;
        }
        return $this->json($response, ['data' => $this->repo()->allForAdmin(), 'presets' => $presets]);
    }

    public function storeProvider(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if ($err = $this->validateProvider($body)) {
            return $this->error($response, $err, 422);
        }
        $id = $this->repo()->create($body);
        if (isset($body['models']) && is_array($body['models'])) {
            $this->repo()->saveModels($id, $body['models']);
        }
        return $this->json($response, ['id' => $id], 201);
    }

    public function updateProvider(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (!$this->repo()->find($id)) {
            return $this->error($response, 'ไม่พบผู้ให้บริการ', 404);
        }
        $body = (array) $request->getParsedBody();
        if ($err = $this->validateProvider($body)) {
            return $this->error($response, $err, 422);
        }
        $this->repo()->update($id, $body);
        if (isset($body['models']) && is_array($body['models'])) {
            $this->repo()->saveModels($id, $body['models']);
        }
        return $this->json($response, ['ok' => true]);
    }

    public function deleteProvider(Request $request, Response $response, array $args): Response
    {
        $this->repo()->delete((int) $args['id']);
        return $this->json($response, ['ok' => true]);
    }

    /** Tests the connection and returns the models the server reports. */
    public function testProvider(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $repo = $this->repo();
        $baseUrl = trim((string) ($body['base_url'] ?? ''));
        $apiKey = trim((string) ($body['api_key'] ?? ''));
        $headers = is_array($body['extra_headers'] ?? null) ? $body['extra_headers'] : [];

        if (!empty($body['provider_id']) && ($stored = $repo->find((int) $body['provider_id']))) {
            if ($apiKey === '' && empty($body['clear_api_key'])) {
                $apiKey = $repo->apiKey($stored);
            }
            if ($baseUrl === '') {
                $baseUrl = $stored['base_url'];
            }
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            return $this->error($response, 'กรุณากรอก Base URL ที่ขึ้นต้นด้วย http:// หรือ https://', 422);
        }

        $started = microtime(true);
        try {
            $models = (new AiClient($baseUrl, $apiKey, $headers, 30))->listModels();
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, [
            'ok' => true,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'models' => $models,
        ]);
    }

    private function validateProvider(array $body): ?string
    {
        if (trim((string) ($body['name'] ?? '')) === '') {
            return 'กรุณาตั้งชื่อผู้ให้บริการ';
        }
        if (!preg_match('#^https?://#i', trim((string) ($body['base_url'] ?? '')))) {
            return 'Base URL ต้องขึ้นต้นด้วย http:// หรือ https://';
        }
        if (isset($body['preset']) && !array_key_exists($body['preset'], AiProviderRepo::PRESETS)) {
            return 'ไม่รู้จักประเภทผู้ให้บริการนี้';
        }
        return null;
    }
}
