<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use App\Services\ListingFeeService;
use App\Services\PosImportService;
use App\Services\PosMatcher;
use App\Services\PosReportParser;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** นำเข้ายอดขายจากระบบ POS ภายนอก (sales.import). */
class PosImportController
{
    use JsonResponse;

    private const MAX_TEXT = 300000;

    private static function service(): PosImportService
    {
        $db = Db::connection();
        $audit = new AuditLogger($db);
        $products = new ProductRepo($db, $audit);
        $sales = new SaleService(
            $db,
            $products,
            new LedgerService($db),
            new DocumentNumberService($db),
            new ListingFeeService($db, new SettingsService($db)),
            $audit
        );
        return new PosImportService($db, $products, $sales, $audit);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => self::service()->recent()]);
    }

    /** Reads the report and returns each line with its saved pair or a suggested one. */
    public function analyze(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $text = (string) ($body['text'] ?? '');
        if (trim($text) === '') {
            return $this->error($response, 'กรุณาวางรายงานยอดขาย หรือเลือกไฟล์', 422);
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            return $this->error($response, 'ไฟล์รายงานใหญ่เกินไป', 422);
        }
        try {
            $parsed = PosReportParser::parse($text);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        if (!$parsed['lines']) {
            return $this->error($response, 'ไม่พบตารางรายการขายในรายงาน (ต้องมีหัวตารางคอลัมน์ชื่อสินค้า และยอดขาย)', 422);
        }

        $user = $request->getAttribute('user');
        $model = isset($body['model']) && (int) $body['model'] > 0 ? (int) $body['model'] : null;
        $result = (new PosMatcher(Db::connection()))->analyze($parsed['lines'], $user, $model);

        $date = (string) ($body['sale_date'] ?? '') ?: ($parsed['date'] ?? '');
        return $this->json($response, [
            'data' => [
                'report_date' => $parsed['date'],
                'sale_date' => $date ?: null,
                'lines' => $result['lines'],
                'warnings' => $parsed['warnings'],
                'ai' => $result['ai'],
                'previous_imports' => $date ? self::service()->forDate($date) : [],
            ],
        ]);
    }

    public function previous(Request $request, Response $response): Response
    {
        $date = (string) ($request->getQueryParams()['date'] ?? '');
        return $this->json($response, ['data' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? self::service()->forDate($date) : []]);
    }

    public function products(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->json($response, ['data' => $q === '' ? [] : (new PosMatcher(Db::connection()))->search($q)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        try {
            $result = self::service()->commit(
                (string) ($body['sale_date'] ?? ''),
                array_map(fn ($l) => (array) $l, (array) ($body['lines'] ?? [])),
                !empty($body['allow_short_stock']),
                trim((string) ($body['source_label'] ?? '')),
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result], 201);
    }

    public function mappings(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->json($response, ['data' => self::service()->mappings($q)]);
    }

    public function deleteMapping(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        try {
            self::service()->deleteMapping((int) $args['id'], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        return $this->json($response, ['ok' => true]);
    }
}
