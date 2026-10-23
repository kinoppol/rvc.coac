<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\CapitalService;
use App\Services\DocumentNumberService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** เงินลงทุน: capital put into / taken out of the business (admin / store manager). */
class CapitalController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $from = $this->date($q['from'] ?? null) ?? date('Y-01-01');
        $to = $this->date($q['to'] ?? null) ?? date('Y-m-d');
        return $this->json($response, ['data' => $this->service()->list($from, $to) + [
            'from' => $from, 'to' => $to, 'types' => CapitalService::TYPES, 'methods' => CapitalService::METHODS,
        ]]);
    }

    public function store(Request $request, Response $response): Response
    {
        try {
            $entry = $this->service()->add((array) $request->getParsedBody(), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $entry], 201);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $reason = trim((string) (((array) $request->getParsedBody())['reason'] ?? ''));
        if ($reason === '') {
            return $this->error($response, 'กรุณาระบุเหตุผลที่ยกเลิก', 422);
        }
        try {
            $this->service()->cancel((int) $args['id'], $reason, (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function service(): CapitalService
    {
        $db = Db::connection();
        return new CapitalService($db, new AuditLogger($db), new DocumentNumberService($db));
    }

    private function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }
}
