<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuditController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $logger = new AuditLogger(Db::connection());
        $data = $logger->list([
            'table_name' => $q['table'] ?? null,
            'record_id' => $q['record_id'] ?? null,
            'user_id' => $q['user_id'] ?? null,
            'from' => $q['from'] ?? null,
            'to' => $q['to'] ?? null,
        ], (int) ($q['limit'] ?? 100), (int) ($q['offset'] ?? 0));

        return $this->json($response, ['data' => $data]);
    }
}
