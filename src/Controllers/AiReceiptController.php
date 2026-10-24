<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AiProviderRepo;
use App\Services\AiClient;
use App\Services\AiToolbox;
use App\Services\AiUsageService;
use App\Services\AttachmentService;
use App\Services\ReceiptMarkdownImport;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Step-by-step import of a Markdown receipt from the assistant. The client
 * drives the steps (plan → items in chunks → summary → draft) and shows real
 * progress between them; each request is short enough for small models.
 */
class AiReceiptController
{
    use JsonResponse;

    private const MAX_ROWS = 300;
    private const MAX_ROWS_PER_CALL = 20;

    /** Splits the file into the item rows and the text around them (no AI). */
    public function plan(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        $content = (string) ($body['content'] ?? '');
        $parts = ReceiptMarkdownImport::split(mb_substr($content, 0, 60000));
        $parts['rows'] = array_slice($parts['rows'], 0, self::MAX_ROWS);
        // A receipt file is kept as evidence and filed with the purchase once it is saved.
        $parts['attachment'] = null;
        if ($parts['rows']) {
            try {
                $parts['attachment'] = (new AttachmentService(Db::connection()))
                    ->saveText($content, (string) ($body['file_name'] ?? ''), (int) $request->getAttribute('user')['id'])['token'];
            } catch (\RuntimeException $e) {
                error_log('Keeping receipt file failed: ' . $e->getMessage());
            }
        }
        return $this->json($response, $parts + ['chunk_size' => ReceiptMarkdownImport::CHUNK_SIZE]);
    }

    /** Converts a few rows; a single row the model can't read is parsed directly. */
    public function items(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        $rows = array_values(array_map('strval', array_slice((array) ($body['rows'] ?? []), 0, self::MAX_ROWS_PER_CALL)));
        if (!$rows) {
            return $this->error($response, 'ไม่มีบรรทัดสินค้า', 422);
        }
        [$import, $error] = $this->importer($request, $body);
        if ($error) {
            return $this->error($response, $error, 422);
        }
        try {
            return $this->json($response, ['items' => $import->readItems((string) ($body['columns'] ?? ''), $rows), 'fallback' => false]);
        } catch (\RuntimeException $e) {
            if (count($rows) > 1) {
                return $this->error($response, $e->getMessage(), 422); // the client retries in smaller pieces
            }
            return $this->json($response, [
                'items' => [ReceiptMarkdownImport::parseRow($rows[0])],
                'fallback' => true,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** Reads supplier and totals, and records the whole import once in the usage log. */
    public function summary(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        [$import, $error, $model, $provider] = $this->importer($request, $body);
        if ($error) {
            return $this->error($response, $error, 422);
        }
        $started = microtime(true);
        $result = null;
        $failure = null;
        try {
            $result = $import->readSummary((string) ($body['header'] ?? ''), (string) ($body['footer'] ?? ''));
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        $db = Db::connection();
        try {
            (new AiUsageService($db, new SettingsService($db)))->log([
                'user_id' => (int) $request->getAttribute('user')['id'],
                'channel' => 'chat',
                'category' => 'markdown',
                'prompt' => sprintf('[นำเข้าใบเสร็จ .md แบบแบ่งช่วง] %s (%d รายการ)', mb_substr((string) ($body['file_name'] ?? ''), 0, 120), (int) ($body['item_count'] ?? 0)),
                'model_row_id' => (int) $model['id'],
                'model_name' => $model['model_id'],
                'provider_name' => $provider['name'],
                'image_count' => 0,
                'response_chars' => 0,
                'tool_calls' => ['create_purchase'],
                'status' => 'ok',
                'error_message' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            error_log('AI usage log failed: ' . $e->getMessage());
        }

        // Totals are optional: without them the user still gets the draft, with a warning.
        return $this->json($response, ['summary' => $result, 'error' => $failure]);
    }

    /**
     * Turns the collected receipts into one assistant message with create_purchase
     * calls plus their confirm cards, so approval runs through the normal chat flow.
     */
    public function draft(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        $toolbox = new AiToolbox(Db::connection(), $request->getAttribute('user'), $request->getServerParams()['REMOTE_ADDR'] ?? null);
        $calls = [];
        $pending = [];
        $lines = [];
        foreach (array_slice((array) ($body['receipts'] ?? []), 0, 5) as $r) {
            if (!is_array($r) || empty($r['items']) || !is_array($r['items'])) {
                continue;
            }
            $s = is_array($r['summary'] ?? null) ? $r['summary'] : [];
            $args = [
                'supplier_name' => (string) ($s['supplier_name'] ?? '') ?: 'ไม่ระบุร้าน',
                'supplier_tax_id' => (string) ($s['supplier_tax_id'] ?? ''),
                'receipt_no' => (string) ($s['receipt_no'] ?? ''),
                'receipt_date' => (string) ($s['receipt_date'] ?? ''),
                'items' => array_map(fn ($it) => [
                    'name' => (string) ($it['name'] ?? ''), 'product_name' => (string) ($it['product_name'] ?? ''),
                    'item_type' => $it['item_type'] ?? null, 'barcode' => (string) ($it['barcode'] ?? ''),
                    'qty' => (int) ($it['qty'] ?? 1), 'pack_size' => (int) ($it['pack_size'] ?? 1),
                    'unit_cost' => (float) ($it['unit_cost'] ?? 0), 'line_total' => (float) ($it['line_total'] ?? 0),
                ], array_values(array_filter($r['items'], 'is_array'))),
                'discount' => (float) ($s['discount'] ?? 0),
                'vat' => (float) ($s['vat'] ?? 0),
                'total' => (float) ($s['total'] ?? 0),
                'note' => 'นำเข้าจากไฟล์ ' . mb_substr((string) ($r['file_name'] ?? ''), 0, 120),
            ];
            if (preg_match('/^[a-f0-9]{32}$/', (string) ($r['attachment'] ?? ''))) {
                $args['receipt_file'] = $r['attachment'];
            }
            $id = 'imp_' . bin2hex(random_bytes(6));
            $calls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => 'create_purchase', 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]];
            $details = $toolbox->details('create_purchase', $args);
            $details['warnings'] = array_values(array_unique(array_merge($details['warnings'], array_map('strval', (array) ($r['warnings'] ?? [])))));
            $pending[] = [
                'id' => $id,
                'name' => 'create_purchase',
                'summary' => $toolbox->describe('create_purchase', $args),
                'details' => $details,
                'editable' => true,
                'draft' => $toolbox->formDraft('create_purchase', $args),
            ];
            $lines[] = $this->summaryLine($args, $details);
        }
        if (!$calls) {
            return $this->error($response, 'ไม่มีรายการสินค้าที่อ่านได้', 422);
        }
        $message = [
            'role' => 'assistant',
            'content' => implode("\n\n", $lines) . "\n\nตรวจรายการด้านล่าง แล้วกด **ยืนยัน** เพื่อบันทึกใบซื้อ หรือ **แก้ไขในฟอร์ม** ถ้าต้องการปรับก่อนบันทึก",
            'tool_calls' => $calls,
            'model' => mb_substr((string) ($body['model_label'] ?? ''), 0, 100),
        ];
        return $this->json($response, ['message' => $message, 'pending_actions' => $pending]);
    }

    private function summaryLine(array $args, array $details): string
    {
        $sum = $details['subtotal'];
        $net = round($sum - $args['discount'], 2);
        $check = $args['total'] <= 0 ? 'ไม่พบยอดสุทธิในไฟล์ จึงตรวจยอดไม่ได้'
            : (abs($net - $args['total']) < 0.01 ? '✓ ตรงกับยอดสุทธิในใบเสร็จ' : sprintf('⚠ ไม่ตรงกับยอดสุทธิในใบเสร็จ ฿%s', number_format($args['total'], 2)));
        return sprintf(
            "**%s**%s%s\n- %d รายการ รวม ฿%s%s → สุทธิ ฿%s %s",
            $args['supplier_name'],
            $args['receipt_no'] !== '' ? ' เลขที่ ' . $args['receipt_no'] : '',
            $args['receipt_date'] !== '' ? ' วันที่ ' . $args['receipt_date'] : '',
            count($args['items']),
            number_format($sum, 2),
            $args['discount'] > 0 ? ' ส่วนลด ฿' . number_format($args['discount'], 2) : '',
            number_format($net, 2),
            $check
        );
    }

    private function denied(Request $request, Response $response): ?Response
    {
        $toolbox = new AiToolbox(Db::connection(), $request->getAttribute('user'), null);
        return in_array('purchase.create', $toolbox->permissions(), true)
            ? null
            : $this->error($response, 'คุณไม่มีสิทธิ์บันทึกใบซื้อสินค้าเข้าร้าน', 403);
    }

    /** @return array{0:?ReceiptMarkdownImport,1:?string,2:?array,3:?array} */
    private function importer(Request $request, array $body): array
    {
        $repo = new AiProviderRepo(Db::connection());
        $role = $request->getAttribute('user')['role'];
        $choice = $body['model'] ?? 'auto';
        $resolved = $choice === 'auto' ? $repo->pickDefault(false, $role) : $repo->resolveEnabledModel((int) $choice, $role);
        if (!$resolved) {
            return [null, 'ไม่พบโมเดลที่เลือก หรือโมเดลถูกปิดใช้งาน / ไม่ได้เปิดให้บทบาทของคุณใช้', null, null];
        }
        [$provider, $model] = $resolved;
        set_time_limit(200);
        $client = new AiClient($provider['base_url'], $repo->apiKey($provider), $repo->extraHeaders($provider), 150);
        return [new ReceiptMarkdownImport($client, $model['model_id']), null, $model, $provider];
    }
}
