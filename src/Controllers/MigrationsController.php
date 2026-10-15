<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Db;
use App\Support\JsonResponse;
use App\Support\MigrationRunner;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MigrationsController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $runner = new MigrationRunner();
        return $this->json($response, ['data' => $runner->status(Db::connection())]);
    }

    public function run(Request $request, Response $response): Response
    {
        $runner = new MigrationRunner();
        try {
            $ran = $runner->runPending(Db::connection());
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 500);
        }
        return $this->json($response, ['ran' => $ran, 'data' => $runner->status(Db::connection())]);
    }

    public function rollback(Request $request, Response $response): Response
    {
        $runner = new MigrationRunner();
        try {
            $rolled = $runner->rollbackLastBatch(Db::connection());
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 500);
        }
        return $this->json($response, ['rolled_back' => $rolled, 'data' => $runner->status(Db::connection())]);
    }
}
