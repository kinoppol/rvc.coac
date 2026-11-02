<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * ผู้บริหาร (executive) look but never change: every request other than a read is turned
 * away while that role is active, except signing out, switching role and approving a
 * QR sign-in of their own.
 */
class ReadOnlyGuard implements MiddlewareInterface
{
    public const ROLE = 'executive';
    private const ALLOWED = ['#/api/auth/logout$#', '#/api/auth/role$#', '#/api/auth/qr/approve$#'];

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');
        $method = strtoupper($request->getMethod());
        if (!$user || ($user['role'] ?? '') !== self::ROLE || in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }
        $path = $request->getUri()->getPath();
        foreach (self::ALLOWED as $re) {
            if (preg_match($re, $path)) {
                return $handler->handle($request);
            }
        }
        $response = (new ResponseFactory())->createResponse(403);
        $response->getBody()->write(json_encode(['error' => 'บัญชีผู้บริหารดูข้อมูลได้อย่างเดียว แก้ไขหรือบันทึกรายการไม่ได้'], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
