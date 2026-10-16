<?php

declare(strict_types=1);

use App\Controllers\AiController;
use App\Controllers\AiUsageController;
use App\Controllers\AttachmentController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\ConsignorController;
use App\Controllers\DashboardController;
use App\Controllers\InventoryController;
use App\Controllers\MigrationsController;
use App\Controllers\OaAdminController;
use App\Controllers\PortalController;
use App\Controllers\PurchaseController;
use App\Controllers\ReceiveController;
use App\Controllers\ReportsController;
use App\Controllers\ReturnController;
use App\Controllers\SalesController;
use App\Controllers\SettingsController;
use App\Controllers\SsoController;
use App\Controllers\StockCountController;
use App\Controllers\UsersController;
use App\Controllers\WithdrawController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleGuard;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

if (!Config::isInstalled()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['error' => 'ยังไม่ได้ติดตั้งระบบ กรุณาเปิด /install.php ก่อนใช้งาน']);
    exit;
}

date_default_timezone_set(Config::get('app.timezone', 'Asia/Bangkok'));

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
// /auth/* (RVC-ONE sign-in) also arrives here rewritten from the project root
// by /.htaccess, because the registered redirect_uri has no /public in it.
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_ends_with($basePath, '/public') && !str_starts_with($requestPath . '/', $basePath . '/')) {
    $basePath = substr($basePath, 0, -strlen('/public'));
}
if ($basePath !== '' && $basePath !== '/') {
    $app->setBasePath($basePath);
}

$app->addErrorMiddleware(false, true, true)
    ->setDefaultErrorHandler(function (Request $request, \Throwable $exception) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        $status = $exception instanceof \Slim\Exception\HttpException ? $exception->getCode() : 500;
        $response->getBody()->write(json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status ?: 500);
    });

// ---- Public routes ----
$app->post('/api/auth/login', [AuthController::class, 'login']);
$app->get('/api/auth/methods', [AuthController::class, 'methods']);
$app->get('/api/portal/{code}/{token}', [PortalController::class, 'show']);

// ---- Sign-in through RVC-ONE (SSO) ----
$app->get('/auth/login', [SsoController::class, 'start']);
$app->post('/auth/callback', [SsoController::class, 'callback']);
$app->get('/auth/callback', [SsoController::class, 'cancelled']);

// ---- Authenticated API ----
$app->group('/api', function ($group) {
    $group->post('/auth/logout', [AuthController::class, 'logout']);
    $group->get('/auth/me', [AuthController::class, 'me']);

    $group->get('/dashboard', [DashboardController::class, 'index']);

    $group->get('/consignors', [ConsignorController::class, 'index']);
    $group->get('/consignors/options', [ConsignorController::class, 'options']);
    $group->get('/consignors/{id}', [ConsignorController::class, 'show']);
    $group->get('/consignors/{id}/passbook', [ConsignorController::class, 'passbook']);
    $group->post('/consignors', [ConsignorController::class, 'store'])
        ->add(new RoleGuard('consignors.create'));

    $group->get('/products/barcode/{code}', [ReceiveController::class, 'lookupBarcode']);
    $group->post('/receive/quote', [ReceiveController::class, 'quote']);
    $group->post('/receive', [ReceiveController::class, 'store'])
        ->add(new RoleGuard('receive.create'));

    $group->get('/returns/stock/{id}', [ReturnController::class, 'stockFor']);
    $group->post('/returns', [ReturnController::class, 'store'])
        ->add(new RoleGuard('return.create'));

    $group->get('/sales/products', [SalesController::class, 'searchProducts']);
    $group->post('/sales', [SalesController::class, 'store'])
        ->add(new RoleGuard('sales.create'));

    $group->get('/withdrawals', [WithdrawController::class, 'index']);
    $group->post('/withdrawals', [WithdrawController::class, 'store'])
        ->add(new RoleGuard('withdraw.create'));
    $group->post('/withdrawals/{id}/approve', [WithdrawController::class, 'approve'])
        ->add(new RoleGuard('withdraw.approve'));

    $group->get('/stockcounts', [StockCountController::class, 'index']);
    $group->get('/stockcounts/{id}', [StockCountController::class, 'show']);
    $group->post('/stockcounts/start', [StockCountController::class, 'start'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/stockcounts/{id}/counts', [StockCountController::class, 'saveCounts'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/stockcounts/{id}/submit', [StockCountController::class, 'submit'])
        ->add(new RoleGuard('stockcount.submit'));
    $group->post('/stockcounts/{id}/approve', [StockCountController::class, 'approve'])
        ->add(new RoleGuard('stockcount.approve'));

    $group->get('/reports/{type}', [ReportsController::class, 'show'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/reports/{type}/export', [ReportsController::class, 'export'])
        ->add(new RoleGuard('reports.export'));

    $group->get('/inventory', [InventoryController::class, 'index'])
        ->add(new RoleGuard('reports.view'));

    $group->get('/settings', [SettingsController::class, 'show'])
        ->add(new RoleGuard('settings.view'));
    $group->put('/settings', [SettingsController::class, 'update'])
        ->add(new RoleGuard('settings.edit'));

    $group->get('/users', [UsersController::class, 'index'])
        ->add(new RoleGuard('settings.edit'));
    $group->post('/users', [UsersController::class, 'store'])
        ->add(new RoleGuard('settings.edit'));
    $group->post('/users/from-oa', [UsersController::class, 'storeFromOa'])
        ->add(new RoleGuard('settings.edit'));
    $group->put('/users/{id:[0-9]+}', [UsersController::class, 'update'])
        ->add(new RoleGuard('settings.edit'));

    $group->get('/oa/status', [OaAdminController::class, 'status'])
        ->add(new RoleGuard('settings.edit'));
    $group->put('/oa/secret', [OaAdminController::class, 'saveSecret'])
        ->add(new RoleGuard('settings.edit'));
    $group->get('/oa/users', [OaAdminController::class, 'search'])
        ->add(new RoleGuard('settings.edit'));
    $group->post('/oa/sync', [OaAdminController::class, 'sync'])
        ->add(new RoleGuard('settings.edit'));

    $group->get('/admin/migrations', [MigrationsController::class, 'index'])
        ->add(new RoleGuard('migrations.manage'));
    $group->post('/admin/migrations/run', [MigrationsController::class, 'run'])
        ->add(new RoleGuard('migrations.manage'));
    $group->post('/admin/migrations/rollback', [MigrationsController::class, 'rollback'])
        ->add(new RoleGuard('migrations.manage'));

    $group->get('/admin/audit-logs', [AuditController::class, 'index'])
        ->add(new RoleGuard('audit.view'));

    $group->post('/attachments', [AttachmentController::class, 'store']);
    $group->get('/attachments/{token:[a-f0-9]{32}}', [AttachmentController::class, 'show']);

    $group->get('/purchases', [PurchaseController::class, 'index'])
        ->add(new RoleGuard('purchase.create'));
    $group->get('/purchases/{id:[0-9]+}', [PurchaseController::class, 'show'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/purchases', [PurchaseController::class, 'store'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/purchases/preview', [PurchaseController::class, 'preview'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/purchases/extract', [PurchaseController::class, 'extract'])
        ->add(new RoleGuard('purchase.create'));

    $group->get('/ai/usage', [AiUsageController::class, 'summary'])
        ->add(new RoleGuard('ai.usage'));
    $group->get('/ai/usage/logs', [AiUsageController::class, 'logs'])
        ->add(new RoleGuard('ai.usage'));
    $group->get('/ai/usage/export', [AiUsageController::class, 'export'])
        ->add(new RoleGuard('ai.usage'));
    $group->put('/ai/usage/rates', [AiUsageController::class, 'updateRates'])
        ->add(new RoleGuard('ai.manage'));

    $group->get('/ai/models', [AiController::class, 'models'])
        ->add(new RoleGuard('ai.use'));
    $group->post('/ai/chat', [AiController::class, 'chat'])
        ->add(new RoleGuard('ai.use'));
    $group->get('/ai/providers', [AiController::class, 'providers'])
        ->add(new RoleGuard('ai.manage'));
    $group->post('/ai/providers', [AiController::class, 'storeProvider'])
        ->add(new RoleGuard('ai.manage'));
    $group->post('/ai/providers/test', [AiController::class, 'testProvider'])
        ->add(new RoleGuard('ai.manage'));
    $group->put('/ai/providers/{id}', [AiController::class, 'updateProvider'])
        ->add(new RoleGuard('ai.manage'));
    $group->delete('/ai/providers/{id}', [AiController::class, 'deleteProvider'])
        ->add(new RoleGuard('ai.manage'));
})->add(new CsrfMiddleware())->add(new AuthMiddleware());

// ---- SPA shell fallback: any other GET request serves the app shell ----
$app->get('/{path:.*}', function (Request $request, Response $response) {
    $html = file_get_contents(__DIR__ . '/app.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->run();
