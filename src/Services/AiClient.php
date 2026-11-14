<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Minimal client for OpenAI-compatible chat APIs (OpenRouter, Google AI
 * Studio's /openai endpoint, vLLM/ThaiLLM, Ollama, LM Studio, ...).
 */
class AiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey = '',
        private array $extraHeaders = [],
        private int $timeout = 90
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** @return array<int,array{id:string,name:string}> */
    public function listModels(): array
    {
        $data = $this->request('GET', '/models');
        $list = $data['data'] ?? $data['models'] ?? (array_is_list($data) ? $data : []);
        $out = [];
        foreach ($list as $m) {
            if (is_string($m)) {
                $out[] = ['id' => $m, 'name' => $m];
                continue;
            }
            $id = (string) ($m['id'] ?? $m['name'] ?? $m['model'] ?? '');
            if ($id === '') {
                continue;
            }
            // Google returns "models/gemini-..." ids; the chat endpoint accepts either form.
            $out[] = [
                'id' => $id,
                'name' => (string) ($m['name'] ?? $m['display_name'] ?? $id),
                'supports_tools' => isset($m['supported_parameters']) && is_array($m['supported_parameters'])
                    ? in_array('tools', $m['supported_parameters'], true)
                    : null,
                // OpenRouter lists input modalities; other servers don't say, so null = unknown.
                'supports_vision' => isset($m['architecture']['input_modalities']) && is_array($m['architecture']['input_modalities'])
                    ? in_array('image', $m['architecture']['input_modalities'], true)
                    : null,
                // Image-generation models (e.g. *-image) are a poor, costly fit for chat.
                // OpenRouter quotes USD per token; stored as USD per 1M tokens.
                'price_in' => isset($m['pricing']['prompt']) && is_numeric($m['pricing']['prompt']) && (float) $m['pricing']['prompt'] >= 0
                    ? round((float) $m['pricing']['prompt'] * 1e6, 4) : null,
                'price_out' => isset($m['pricing']['completion']) && is_numeric($m['pricing']['completion']) && (float) $m['pricing']['completion'] >= 0
                    ? round((float) $m['pricing']['completion'] * 1e6, 4) : null,
                'image_output' => isset($m['architecture']['output_modalities']) && is_array($m['architecture']['output_modalities'])
                    && in_array('image', $m['architecture']['output_modalities'], true),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    /** Sends one chat completion request and returns the first choice's message. */
    public function chat(string $model, array $messages, array $tools = [], array $options = []): array
    {
        $body = ['model' => $model, 'messages' => $messages, 'temperature' => 0.3];
        $body = array_replace($body, array_intersect_key($options, array_flip(['temperature', 'max_tokens'])));
        if ($tools) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }
        try {
            $data = $this->request('POST', '/chat/completions', $body);
        } catch (AiHttpException $e) {
            // Gateways like OpenRouter often recover on a second try after a transient upstream error.
            if (!in_array($e->status, [429, 502, 503, 504], true)) {
                throw $e;
            }
            usleep(1500000);
            $data = $this->request('POST', '/chat/completions', $body);
        }
        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('ผู้ให้บริการ AI ตอบกลับในรูปแบบที่ไม่รู้จัก');
        }
        return $message;
    }

    /** Index of the /ocr request shape the server accepted, reused for the next photo. */
    private ?int $ocrShape = null;

    /**
     * Reads an image with an OCR model and returns its text as Markdown.
     * Providers don't agree on the /ocr body, so the common shapes are tried
     * in turn (JSON with a data URL, then a multipart file upload) until one is
     * accepted. Only when the server has no /ocr route at all is the model
     * asked through chat/completions.
     */
    public function ocr(string $model, string $dataUrl): string
    {
        $prompt = 'ถอดข้อความทั้งหมดในรูปใบเสร็จนี้เป็น Markdown ให้ตรงตามต้นฉบับ ตารางรายการสินค้าให้เป็นตาราง Markdown (| ... |) '
            . 'ห้ามสรุป ห้ามแปล ห้ามเพิ่มข้อความอื่น ตอบเฉพาะ Markdown';
        [$mime, $base64] = preg_match('#^data:([^;,]+);base64,(.*)$#s', $dataUrl, $m) ? [$m[1], $m[2]] : ['image/jpeg', ''];
        $ext = ['image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'jpg';
        $file = fn () => new \CURLStringFile((string) base64_decode($base64), 'receipt.' . $ext, $mime);
        $shapes = [
            // TokenMe (Paxa OCR): raw base64 in "document", output format chosen by "output".
            fn () => ['model' => $model, 'document' => $base64, 'output' => 'markdown'],
            fn () => ['model' => $model, 'document' => ['type' => 'image_url', 'image_url' => $dataUrl]],
            fn () => ['model' => $model, 'document' => ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]],
            fn () => ['model' => $model, 'image' => $dataUrl],
            fn () => ['model' => $model, 'image_url' => $dataUrl],
            fn () => ['model' => $model, 'image' => $base64],
            fn () => ['model' => $model, 'file' => $file()],
            fn () => ['model' => $model, 'image' => $file()],
            fn () => ['model' => $model, 'document' => $file()],
        ];
        $order = array_keys($shapes);
        if ($this->ocrShape !== null) {
            $order = array_merge([$this->ocrShape], array_diff($order, [$this->ocrShape]));
        }

        $data = null;
        $errors = [];
        foreach ($order as $i) {
            try {
                $data = $this->request('POST', '/ocr', $shapes[$i]());
                $this->ocrShape = $i;
                break;
            } catch (AiHttpException $e) {
                if (in_array($e->status, [404, 405], true)) {
                    $errors = null; // no /ocr route: ask the model through chat instead
                    break;
                }
                if (!in_array($e->status, [400, 415, 422], true)) {
                    throw $e;
                }
                $errors[$e->getMessage()] = true; // body shape rejected: try the next one
            }
        }
        if ($data === null && $errors === null) {
            $data = $this->request('POST', '/chat/completions', ['model' => $model, 'temperature' => 0, 'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ]]]]);
        }
        if ($data === null) {
            throw new \RuntimeException('endpoint /ocr ของผู้ให้บริการไม่รับรูปแบบคำขอที่ระบบรู้จัก — ' . implode(' / ', array_slice(array_keys($errors), 0, 3)));
        }
        $text = trim(self::ocrText($data));
        if ($text === '') {
            throw new \RuntimeException('โมเดล OCR ไม่พบข้อความในรูป');
        }
        // Some OCR models wrap the whole answer in a ```markdown fence.
        return trim(preg_replace('/^```(?:markdown|md)?\s*\n(.*)\n```$/s', '$1', $text));
    }

    /** Text from the common OCR/chat response shapes. */
    private static function ocrText(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }
        if (!is_array($data)) {
            return '';
        }
        if (isset($data['choices'][0]['message']['content'])) {
            $c = $data['choices'][0]['message']['content'];
            return is_array($c) ? implode("\n", array_map(fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $c)) : (string) $c;
        }
        foreach (['pages', 'data', 'results'] as $listKey) {
            if (isset($data[$listKey]) && is_array($data[$listKey]) && array_is_list($data[$listKey])) {
                $parts = array_filter(array_map(fn ($p) => trim(self::ocrText($p)), $data[$listKey]), 'strlen');
                if ($parts) {
                    return implode("\n\n", $parts);
                }
            }
        }
        // The longest candidate wins, so echoed options such as "output":"markdown" are never taken for the text.
        $best = '';
        foreach (['markdown', 'md', 'text', 'content', 'output', 'result', 'document', 'data'] as $key) {
            if (isset($data[$key])) {
                $text = self::ocrText($data[$key]);
                if (mb_strlen(trim($text)) > mb_strlen(trim($best))) {
                    $best = $text;
                }
            }
        }
        return $best;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new \RuntimeException('Base URL ต้องขึ้นต้นด้วย http:// หรือ https://');
        }

        $headers = ['Accept: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        foreach ($this->extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null && array_filter($body, fn ($v) => $v instanceof \CURLStringFile)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body); // multipart/form-data upload
        } elseif ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('เชื่อมต่อผู้ให้บริการ AI ไม่ได้: ' . $err);
        }

        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            $detail = self::errorDetail($data, (string) $raw);
            throw new AiHttpException($status, "ผู้ให้บริการ AI ตอบกลับ HTTP {$status}" . self::statusHint($status) . ($detail !== '' ? " — {$detail}" : ''));
        }
        if (!is_array($data)) {
            throw new \RuntimeException('ผู้ให้บริการ AI ตอบกลับข้อมูลที่ไม่ใช่ JSON');
        }
        // Some servers (e.g. OpenRouter) report upstream failures as 200 + error body.
        if (isset($data['error']) && !isset($data['choices']) && !isset($data['data'])) {
            $code = is_array($data['error']) ? (int) ($data['error']['code'] ?? 0) : 0;
            throw new AiHttpException($code, 'ผู้ให้บริการ AI แจ้งข้อผิดพลาด: ' . self::errorDetail($data, (string) $raw));
        }
        // OpenRouter can also put an upstream error inside the choice itself.
        if (isset($data['choices'][0]['error'])) {
            $err = $data['choices'][0]['error'];
            throw new AiHttpException((int) ($err['code'] ?? 502), 'ผู้ให้บริการ AI แจ้งข้อผิดพลาด: ' . self::errorDetail(['error' => $err], ''));
        }
        return $data;
    }

    /** Best human-readable reason from an error body (OpenRouter puts the upstream text in metadata.raw). */
    private static function errorDetail(mixed $data, string $raw): string
    {
        if (is_array($data)) {
            $err = $data['error'] ?? $data;
            if (!is_array($err)) {
                return mb_substr((string) $err, 0, 300);
            }
            $parts = [];
            $msg = $err['message'] ?? $data['message'] ?? null;
            if ($msg) {
                $parts[] = is_array($msg) ? json_encode($msg, JSON_UNESCAPED_UNICODE) : (string) $msg;
            }
            if (!empty($err['metadata']['provider_name'])) {
                $parts[] = 'ผู้ให้บริการต้นทาง: ' . $err['metadata']['provider_name'];
            }
            if (!empty($err['metadata']['raw'])) {
                $rawMeta = is_string($err['metadata']['raw']) ? $err['metadata']['raw'] : json_encode($err['metadata']['raw'], JSON_UNESCAPED_UNICODE);
                $parts[] = mb_substr($rawMeta, 0, 300);
            }
            if ($parts) {
                return implode(' | ', $parts);
            }
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
        return mb_substr($text, 0, 200);
    }

    private static function statusHint(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => ' (API Key ไม่ถูกต้องหรือไม่มีสิทธิ์)',
            $status === 402 => ' (เครดิตไม่พอ)',
            $status === 404 => ' (ไม่พบโมเดลนี้)',
            $status === 429 => ' (เรียกใช้ถี่เกินไป ลองใหม่อีกครั้ง)',
            $status >= 500 => ' (ผู้ให้บริการต้นทางขัดข้อง ลองใหม่ หรือเลือกโมเดลอื่น)',
            default => '',
        };
    }
}
