<?php

declare(strict_types=1);

use App\Controllers\AiController;
use App\Controllers\AiReceiptController;
use App\Controllers\AiUsageController;
use App\Controllers\CapitalController;
use App\Controllers\DrawerController;
use App\Controllers\WageController;
use App\Controllers\AttachmentController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\ConsignorController;
use App\Controllers\DashboardController;
use App\Controllers\InventoryController;
use App\Controllers\MigrationsController;
use App\Controllers\MyConsignorController;
use App\Controllers\OaAdminController;
use App\Controllers\PerfController;
use App\Controllers\PortalController;
use App\Controllers\PosImportController;
use App\Controllers\PurchaseController;
use App\Controllers\ReceiveController;
use App\Controllers\ReportsController;
use App\Controllers\ReturnController;
use App\Controllers\SaleEditController;
use App\Controllers\ScanLinkController;
use App\Controllers\SalesController;
use App\Controllers\SettingsController;
use App\Controllers\SsoController;
use App\Controllers\StockCountController;
use App\Controllers\UsersController;
use App\Controllers\WithdrawController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleGuard;
use App\Services\SettingsService;
use App\Support\AppShell;
use App\Support\Config;
use App\Support\Db;
use App\Support\RequestLog;
use App\Support\SessionLifetime;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$requestStarted = microtime(true);
if (session_status() === PHP_SESSION_NONE) {
    // PHP locks the session file while a request holds it open, so one slow request
    // (an AI receipt read, a POS import) would make every other request of that user
    // wait. Only sign-in/out, role switching and impersonation change the session;
    // every other request reads it and releases the lock at once.
    $writesSession = preg_match('#/auth/|/impersonate$#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) === 1;
    // Idle lifetime set by the admin (Settings > ผู้ใช้งาน); see SessionLifetime.
    $sessionTtl = SessionLifetime::hours() * 3600;
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_lifetime' => $sessionTtl,
        'gc_maxlifetime' => $sessionTtl,
        'save_path' => SessionLifetime::savePath(),
        'read_and_close' => !$writesSession,
    ]);
    SessionLifetime::keepAlive($sessionTtl);
}
$sessionWait = microtime(true) - $requestStarted;

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
$app->post('/api/auth/qr', [AuthController::class, 'qrStart']);
$app->get('/api/auth/qr/status', [AuthController::class, 'qrStatus']);
$app->get('/api/portal/{code}/{token}', [PortalController::class, 'show']);

// ---- Sign-in through RVC-ONE (SSO) ----
$app->get('/auth/login', [SsoController::class, 'start']);
$app->post('/auth/callback', [SsoController::class, 'callback']);
$app->get('/auth/callback', [SsoController::class, 'cancelled']);

// ---- Authenticated API ----
$app->group('/api', function ($group) {
    $group->post('/auth/logout', [AuthController::class, 'logout']);
    $group->get('/auth/me', [AuthController::class, 'me']);
    $group->post('/auth/role', [AuthController::class, 'switchRole']);
    $group->get('/auth/qr/info', [AuthController::class, 'qrInfo']);
    $group->post('/auth/qr/approve', [AuthController::class, 'qrApprove']);

    $group->get('/my/passbook', [MyConsignorController::class, 'passbook'])
        ->add(new RoleGuard('consignor.portal'));
    $group->get('/my/products', [MyConsignorController::class, 'products'])
        ->add(new RoleGuard('consignor.portal'));
    $group->get('/my/requests', [MyConsignorController::class, 'requests'])
        ->add(new RoleGuard('consignor.portal'));
    $group->post('/my/requests', [MyConsignorController::class, 'storeRequest'])
        ->add(new RoleGuard('consignor.portal'));
    $group->post('/my/requests/{id:[0-9]+}/cancel', [MyConsignorController::class, 'cancelRequest'])
        ->add(new RoleGuard('consignor.portal'));
    $group->post('/my/requests/{id:[0-9]+}/delete', [MyConsignorController::class, 'deleteRequest'])
        ->add(new RoleGuard('consignor.portal'));
    $group->post('/my/requests/{id:[0-9]+}/restore', [MyConsignorController::class, 'restoreRequest'])
        ->add(new RoleGuard('consignor.portal'));
    $group->post('/my/quote', [ReceiveController::class, 'quote'])
        ->add(new RoleGuard('consignor.portal'));

    $group->get('/dashboard', [DashboardController::class, 'index']);
    $group->get('/dashboard/sales-chart', [DashboardController::class, 'salesChart']);
    $group->get('/dashboard/detail/{key:[a-z_]+}', [DashboardController::class, 'detail']);

    $group->get('/consignors', [ConsignorController::class, 'index']);
    $group->get('/consignors/options', [ConsignorController::class, 'options']);
    $group->get('/consignors/directory', [ConsignorController::class, 'directory'])
        ->add(new RoleGuard('consignors.create'));
    $group->post('/consignors/from-oa', [ConsignorController::class, 'importFromOa'])
        ->add(new RoleGuard('consignors.create'));
    $group->get('/consignors/{id}', [ConsignorController::class, 'show']);
    $group->get('/consignors/{id}/passbook', [ConsignorController::class, 'passbook']);
    $group->post('/consignors', [ConsignorController::class, 'store'])
        ->add(new RoleGuard('consignors.create'));

    $group->get('/products/barcode/{code}', [ReceiveController::class, 'lookupBarcode']);
    $group->post('/receive/quote', [ReceiveController::class, 'quote']);
    $group->post('/receive', [ReceiveController::class, 'store'])
        ->add(new RoleGuard('receive.create'));
    $group->get('/receive/history', [ReceiveController::class, 'history'])
        ->add(new RoleGuard('receive.create'));
    $group->get('/receive/docs', [ReceiveController::class, 'documents'])
        ->add(new RoleGuard('receive.create'));
    $group->get('/receive/docs/{id:[0-9]+}', [ReceiveController::class, 'document'])
        ->add(new RoleGuard('receive.create'));
    $group->put('/receive/items/{id:[0-9]+}', [ReceiveController::class, 'updateItem'])
        ->add(new RoleGuard('receive.create'));
    $group->get('/receive/requests',[ReceiveController::class, 'requests'])
        ->add(new RoleGuard('receive.create'));
    $group->post('/receive/requests/{id:[0-9]+}/reject', [ReceiveController::class, 'rejectRequest'])
        ->add(new RoleGuard('receive.create'));

    $group->get('/returns/stock/{id}', [ReturnController::class, 'stockFor']);
    $group->post('/returns', [ReturnController::class, 'store'])
        ->add(new RoleGuard('return.create'));

    $group->get('/sales/products', [SalesController::class, 'searchProducts']);
    $group->get('/sales/options', [SalesController::class, 'options']);
    $group->post('/sales', [SalesController::class, 'store'])
        ->add(new RoleGuard('sales.create'));
    $group->get('/sales/mine', [SaleEditController::class, 'mine'])
        ->add(new RoleGuard('sales.create'));
    $group->get('/sales/{id:[0-9]+}', [SaleEditController::class, 'show']);
    $group->post('/sales/{id:[0-9]+}/edit-requests', [SaleEditController::class, 'store'])
        ->add(new RoleGuard('sales.create'));
    $group->post('/sale-edits/{id:[0-9]+}/cancel', [SaleEditController::class, 'cancel'])
        ->add(new RoleGuard('sales.create'));
    $group->get('/sale-edits', [SaleEditController::class, 'index'])
        ->add(new RoleGuard('sales.edit.approve'));
    $group->post('/sale-edits/{id:[0-9]+}/approve', [SaleEditController::class, 'approve'])
        ->add(new RoleGuard('sales.edit.approve'));
    $group->post('/sale-edits/{id:[0-9]+}/reject', [SaleEditController::class, 'reject'])
        ->add(new RoleGuard('sales.edit.approve'));
    $group->put('/sales/{id:[0-9]+}/payments', [DrawerController::class, 'editPayments'])
        ->add(new RoleGuard('drawer.manage'));

    $group->get('/drawer/state', [DrawerController::class, 'state'])
        ->add(new RoleGuard('sales.create'));
    $group->post('/drawer/open', [DrawerController::class, 'open'])
        ->add(new RoleGuard('sales.create'));
    $group->post('/drawer/skip', [DrawerController::class, 'skip'])
        ->add(new RoleGuard('sales.create'));
    $group->post('/drawer/close', [DrawerController::class, 'close'])
        ->add(new RoleGuard('sales.create'));
    $group->get('/drawer/summary', [DrawerController::class, 'summary'])
        ->add(new RoleGuard('sales.create'));
    $group->put('/drawer/{date}/opening', [DrawerController::class, 'adjustOpening'])
        ->add(new RoleGuard('drawer.manage'));
    $group->get('/drawer/behavior', [DrawerController::class, 'behavior'])
        ->add(new RoleGuard('drawer.manage'));

    $group->get('/wages', [WageController::class, 'index'])
        ->add(new RoleGuard('wages.manage'));
    $group->post('/wages', [WageController::class, 'store'])
        ->add(new RoleGuard('wages.manage'));
    $group->post('/wages/{id:[0-9]+}/cancel', [WageController::class, 'cancel'])
        ->add(new RoleGuard('wages.manage'));

    $group->get('/pos-import', [PosImportController::class, 'index'])
        ->add(new RoleGuard('sales.import'));
    $group->post('/pos-import', [PosImportController::class, 'store'])
        ->add(new RoleGuard('sales.import'));
    $group->get('/pos-import/{id:[0-9]+}', [PosImportController::class, 'show'])
        ->add(new RoleGuard('sales.import'));
    $group->post('/pos-import/{id:[0-9]+}/cancel', [PosImportController::class, 'cancel'])
        ->add(new RoleGuard('sales.import.cancel'));
    $group->get('/pos-import/{id:[0-9]+}/items/{item:[0-9]+}/pairs', [PosImportController::class, 'linePairs'])
        ->add(new RoleGuard('sales.import.cancel'));
    $group->post('/pos-import/{id:[0-9]+}/items/{item:[0-9]+}/repair', [PosImportController::class, 'repair'])
        ->add(new RoleGuard('sales.import.cancel'));
    $group->post('/pos-import/analyze',[PosImportController::class, 'analyze'])
        ->add(new RoleGuard('sales.import'));
    $group->get('/pos-import/models', [PosImportController::class, 'models'])
        ->add(new RoleGuard('sales.import'));
    $group->post('/pos-import/suggest', [PosImportController::class, 'suggest'])
        ->add(new RoleGuard('sales.import'));
    $group->get('/pos-import/previous', [PosImportController::class, 'previous'])
        ->add(new RoleGuard('sales.import'));
    $group->get('/pos-import/products', [PosImportController::class, 'products'])
        ->add(new RoleGuard('sales.import'));
    $group->get('/pos-import/mappings', [PosImportController::class, 'mappings'])
        ->add(new RoleGuard('sales.import'));
    $group->delete('/pos-import/mappings/{id:[0-9]+}', [PosImportController::class, 'deleteMapping'])
        ->add(new RoleGuard('sales.import'));

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
    $group->get('/stockcounts/{id:[0-9]+}/lookup', [StockCountController::class, 'lookup'])
        ->add(new RoleGuard('stockcount.create'));
    $group->get('/stockcounts/{id:[0-9]+}/search', [StockCountController::class, 'search'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/stockcounts/{id:[0-9]+}/count', [StockCountController::class, 'countOne'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/stockcounts/{id:[0-9]+}/photo', [StockCountController::class, 'addPhoto'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/stockcounts/{id:[0-9]+}/barcode', [StockCountController::class, 'assignBarcode'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/products/{id:[0-9]+}/photo', [StockCountController::class, 'savePhoto'])
        ->add(new RoleGuard('stockcount.create'));
    $group->get('/products/{id:[0-9]+}/photo', [StockCountController::class, 'photo']);
    $group->post('/products/{id:[0-9]+}/photo/thumb', [StockCountController::class, 'saveThumb']);
    $group->post('/stockcounts/{id:[0-9]+}/cancel', [StockCountController::class, 'cancel'])
        ->add(new RoleGuard('stockcount.create'));
    $group->post('/stockcounts/{id}/submit', [StockCountController::class, 'submit'])
        ->add(new RoleGuard('stockcount.submit'));
    $group->post('/stockcounts/{id}/approve', [StockCountController::class, 'approve'])
        ->add(new RoleGuard('stockcount.approve'));

    $group->get('/capital', [CapitalController::class, 'index'])
        ->add(new RoleGuard('capital.manage'));
    $group->post('/capital', [CapitalController::class, 'store'])
        ->add(new RoleGuard('capital.manage'));
    $group->post('/capital/{id:[0-9]+}/cancel', [CapitalController::class, 'cancel'])
        ->add(new RoleGuard('capital.manage'));

    $group->get('/reports/capital/source', [ReportsController::class, 'capitalSource'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/reports/statement/source', [ReportsController::class, 'statementSource'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/reports/{type}', [ReportsController::class, 'show'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/reports/{type}/export', [ReportsController::class, 'export'])
        ->add(new RoleGuard('reports.export'));

    $group->get('/inventory', [InventoryController::class, 'index'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/inventory/lookup', [InventoryController::class, 'lookup'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/inventory/search', [InventoryController::class, 'search'])
        ->add(new RoleGuard('reports.view'));
    $group->post('/inventory/{id:[0-9]+}/assign-barcode', [InventoryController::class, 'assignBarcode'])
        ->add(new RoleGuard('purchase.create'));

    // A phone as a barcode scanner for a computer: the computer side needs the inventory
    // page; any signed-in phone holding the link's code may send barcodes to it.
    $group->post('/scan-links', [ScanLinkController::class, 'open'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/scan-links/{code:[a-f0-9]{32}}/codes', [ScanLinkController::class, 'codes'])
        ->add(new RoleGuard('reports.view'));
    $group->post('/scan-links/{code:[a-f0-9]{32}}/close', [ScanLinkController::class, 'close'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/scan-links/{code:[a-f0-9]{32}}', [ScanLinkController::class, 'join']);
    $group->post('/scan-links/{code:[a-f0-9]{32}}/codes', [ScanLinkController::class, 'send']);

    $group->get('/inventory/{id:[0-9]+}/movements', [InventoryController::class, 'movements'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/inventory/supplier-options', [InventoryController::class, 'supplierOptions'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/inventory/purchase-orders', [InventoryController::class, 'createOrders'])
        ->add(new RoleGuard('purchase.create'));
    $group->get('/inventory/order-check',[InventoryController::class, 'orderCheck'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/inventory/document',[InventoryController::class, 'document'])
        ->add(new RoleGuard('reports.view'));
    $group->get('/inventory/zero-cost', [InventoryController::class, 'zeroCost'])
        ->add(new RoleGuard('reports.view'));
    $group->post('/inventory/zero-cost', [InventoryController::class, 'fillZeroCost'])
        ->add(new RoleGuard('reports.view'));
    $group->put('/inventory/{id:[0-9]+}/barcodes', [InventoryController::class, 'updateBarcodes'])
        ->add(new RoleGuard('purchase.create'));
    $group->get('/inventory/{id:[0-9]+}/name-options', [InventoryController::class, 'nameOptions'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/inventory/{id:[0-9]+}/consign',[InventoryController::class, 'convertToConsignment'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/inventory/{id:[0-9]+}/claim-sales', [InventoryController::class, 'claimStoreSales'])
        ->add(new RoleGuard('purchase.create'));
    $group->get('/consignors/{id:[0-9]+}/unclaimed-sales', [InventoryController::class, 'unclaimedSales']);
    $group->post('/consignors/{id:[0-9]+}/claim-sales', [InventoryController::class, 'claimConsignorSales']);

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
    $group->post('/users/{id:[0-9]+}/impersonate', [AuthController::class, 'impersonate'])
        ->add(new RoleGuard('settings.edit'));

    $group->get('/oa/status', [OaAdminController::class, 'status'])
        ->add(new RoleGuard('settings.edit'));
    $group->put('/oa/secret', [OaAdminController::class, 'saveSecret'])
        ->add(new RoleGuard('settings.edit'));
    $group->put('/oa/sync-type', [OaAdminController::class, 'saveSyncType'])
        ->add(new RoleGuard('settings.edit'));
    $group->get('/oa/users',[OaAdminController::class, 'search'])
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

    $group->get('/admin/perf', [PerfController::class, 'requests'])
        ->add(new RoleGuard('settings.edit'));
    $group->post('/admin/thumbnails', [PerfController::class, 'thumbnails'])
        ->add(new RoleGuard('settings.edit'));

    $group->post('/attachments', [AttachmentController::class, 'store']);
    $group->get('/attachments/{token:[a-f0-9]{32}}', [AttachmentController::class, 'show']);

    $group->get('/purchases', [PurchaseController::class, 'index'])
        ->add(new RoleGuard('purchase.create'));
    $group->get('/purchases/{id:[0-9]+}', [PurchaseController::class, 'show'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/purchases', [PurchaseController::class, 'store'])
        ->add(new RoleGuard('purchase.create'));
    $group->put('/purchases/{id:[0-9]+}', [PurchaseController::class, 'update'])
        ->add(new RoleGuard('purchase.create'));
    $group->put('/purchases/{id:[0-9]+}/attachments', [PurchaseController::class, 'attachments'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/purchases/{id:[0-9]+}/place', [PurchaseController::class, 'place'])
        ->add(new RoleGuard('purchase.create'));
    $group->post('/purchases/{id:[0-9]+}/cancel', [PurchaseController::class, 'cancel'])
        ->add(new RoleGuard('purchase.create'));
    $group->get('/purchases/products', [PurchaseController::class, 'products'])
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
    foreach (['plan', 'items', 'summary', 'draft'] as $step) {
        $group->post("/ai/receipt/{$step}", [AiReceiptController::class, $step])
            ->add(new RoleGuard('ai.use'));
    }
    $group->get('/ai/providers', [AiController::class, 'providers'])
        ->add(new RoleGuard('ai.manage'));
    $group->post('/ai/providers', [AiController::class, 'storeProvider'])
        ->add(new RoleGuard('ai.manage'));
    $group->put('/ai/default-model', [AiController::class, 'updateDefaultModel'])
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
    try {
        $mode = (string) (new SettingsService(Db::connection()))->get('asset_mode');
    } catch (\Throwable $e) {
        $mode = 'development';
    }
    $response->getBody()->write(AppShell::render($html, $mode));
    // The shell names the asset versions, so it is never cached itself.
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-cache, must-revalidate');
});

// ---- Request timing (outermost): a Server-Timing header for the browser's
// Network panel, and one line per request in storage/logs (Settings > ประสิทธิภาพ). ----
$app->add(function (Request $request, $handler) use ($sessionWait) {
    $response = $handler->handle($request);
    $total = (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000;
    $response = $response->withHeader('X-App-Build', AppShell::build())->withHeader('Server-Timing', sprintf('session;desc="session wait";dur=%.0f, total;dur=%.0f', $sessionWait * 1000, $total));
    RequestLog::write($request->getMethod(), $request->getUri()->getPath(), $total, $sessionWait * 1000, $response->getStatusCode(), (int) ($_SESSION['user_id'] ?? 0));
    // session_start() sends "Pragma: no-cache" and an Expires in 1981. Chrome reads that
    // Pragma as no-cache, so a cacheable response (a product photo) would still be asked
    // for again on every load (a 304 round trip each). Drop them where we allow caching.
    if (preg_match('/max-age=[1-9]/', $response->getHeaderLine('Cache-Control'))) {
        header_remove('Pragma');
        header_remove('Expires');
    }
    return $response;
});

$app->run();
