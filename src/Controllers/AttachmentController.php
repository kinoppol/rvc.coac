<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AttachmentService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AttachmentController
{
    use JsonResponse;

    private function can(array $user, string $perm): bool
    {
        return (new UserRepo(Db::connection()))->hasPermission($user['role'], $perm);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->can($user, 'ai.use') && !$this->can($user, 'purchase.create')) {
            return $this->error($response, 'ไม่มีสิทธิ์อัปโหลดไฟล์', 403);
        }
        $body = (array) $request->getParsedBody();
        try {
            $row = (new AttachmentService(Db::connection()))->saveDataUrl((string) ($body['data_url'] ?? ''), (int) $user['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => (new AttachmentService(Db::connection()))->publicInfo($row)], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $service = new AttachmentService(Db::connection());
        $row = $service->findByToken((string) $args['token']);
        $allowed = $row && ((int) $row['uploaded_by'] === (int) $user['id'] || $this->can($user, 'purchase.create'));
        if (!$allowed) {
            return $this->error($response, 'ไม่พบไฟล์', 404);
        }
        try {
            $path = $service->absolutePath($row);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $row['mime'])
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
