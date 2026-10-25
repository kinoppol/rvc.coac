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
        $service = new AttachmentService(Db::connection());
        try {
            // A photo arrives as a data URL; a .md receipt file as its text.
            $row = isset($body['text'])
                ? $service->saveText((string) $body['text'], (string) ($body['name'] ?? ''), (int) $user['id'])
                : $service->saveDataUrl((string) ($body['data_url'] ?? ''), (int) $user['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->publicInfo($row)], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $service = new AttachmentService(Db::connection());
        $row = $service->findByToken((string) $args['token']);
        // Counting photos are for whoever counts or approves counts.
        $countPhoto = $row && str_starts_with((string) $row['file_path'], 'counts/')
            && ($this->can($user, 'stockcount.create') || $this->can($user, 'stockcount.approve') || $this->can($user, 'reports.view'));
        $allowed = $row && ((int) $row['uploaded_by'] === (int) $user['id'] || $this->can($user, 'purchase.create') || $countPhoto);
        if (!$allowed) {
            return $this->error($response, 'ไม่พบไฟล์', 404);
        }
        try {
            $path = $service->absolutePath($row);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        $response->getBody()->write((string) file_get_contents($path));
        if (!AttachmentService::isImage($row)) {
            // Shown as plain text in the browser; ?download saves it under its own name.
            $name = $row['original_name'] ?: 'receipt.md';
            $response = $response->withHeader('Content-Disposition', (isset($request->getQueryParams()['download']) ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($name));
        }
        return $response
            ->withHeader('Content-Type', AttachmentService::isImage($row) ? $row['mime'] : 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
