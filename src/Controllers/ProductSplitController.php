<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\ProductSplitService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Splitting a store product (a pack of pieces with barcodes of their own) into separate products. */
class ProductSplitController
{
    use JsonResponse;

    private function service(): ProductSplitService
    {
        $db = Db::connection();
        return new ProductSplitService($db, new DocumentNumberService($db), new AuditLogger($db));
    }

    /** GET /inventory/{id}/split — the product, the pieces of its last split and its split history. */
    public function info(Request $request, Response $response, array $args): Response
    {
        return $this->run($response, fn () => $this->service()->info((int) $args['id']));
    }

    /** POST /inventory/{id}/split {items: [{product_id?, name?, barcode?, sale_price?, qty}], note?} */
    public function split(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return $this->run($response, function () use ($args, $body, $user, $ip) {
            $service = $this->service();
            $split = $service->split((int) $args['id'], array_slice((array) ($body['items'] ?? []), 0, 200), (string) ($body['note'] ?? ''), (int) $user['id'], $ip);
            return ['split' => $split] + $service->info((int) $args['id']);
        }, 201);
    }

    /** POST /inventory/splits/{id}/cancel {reason} — the pieces go back to the source product. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return $this->run($response, function () use ($args, $body, $user, $ip) {
            $service = $this->service();
            $sourceId = $service->sourceOf((int) $args['id']);
            if ($sourceId === null) {
                throw new \RuntimeException('ไม่พบเอกสารแยกสินค้า');
            }
            $service->cancel((int) $args['id'], (string) ($body['reason'] ?? ''), (int) $user['id'], $ip);
            return $service->info($sourceId);
        });
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
