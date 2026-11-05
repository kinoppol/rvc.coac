<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\DamageService;
use App\Services\DocumentNumberService;
use App\Services\ReportService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Damaged store goods written off stock, and their statistics. */
class DamageController
{
    use JsonResponse;

    private function service(): DamageService
    {
        $db = Db::connection();
        return new DamageService($db, new DocumentNumberService($db), new AuditLogger($db));
    }

    /** GET /inventory/{id}/damage — the product, its purchases and its damage history. */
    public function info(Request $request, Response $response, array $args): Response
    {
        return $this->run($response, fn () => $this->service()->info((int) $args['id']));
    }

    /** POST /inventory/{id}/damage {qty, reason, damage_date?, purchase_document_id?, note?} */
    public function record(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return $this->run($response, function () use ($args, $body, $user, $ip) {
            $service = $this->service();
            $record = $service->record((int) $args['id'], $body, (int) $user['id'], $ip);
            return ['record' => $record] + $service->info((int) $args['id']);
        }, 201);
    }

    /** POST /inventory/damages/{id}/cancel {reason} — the pieces go back into stock. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return $this->run($response, function () use ($args, $body, $user, $ip) {
            $service = $this->service();
            $productId = $service->cancel((int) $args['id'], (string) ($body['reason'] ?? ''), (int) $user['id'], $ip);
            return ['product_id' => $productId];
        });
    }

    /** GET /inventory/damages/report?range=…&from=…&to=… */
    public function report(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
        [$from, $to] = ReportService::resolveRange((string) ($q['range'] ?? 'month'), $date($q['from'] ?? null), $date($q['to'] ?? null));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return $this->run($response, fn () => $this->service()->report($from, $to));
    }

    private function run(Response $response, callable $fn, int $status = 200): Response
    {
        try {
            return $this->json($response, ['data' => $fn()], $status);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }
}
