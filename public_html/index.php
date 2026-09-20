<?php

declare(strict_types=1);

use SyncBridge\Repository\EventQueryRepository;
use SyncBridge\Repository\EventRepository;
use SyncBridge\Repository\WorkerRepository;
use SyncBridge\Security\WebhookSigner;
use SyncBridge\Service\DemoErpAdapter;
use SyncBridge\Service\DemoPayloadFactory;
use SyncBridge\Service\EventWorker;
use SyncBridge\Service\WebhookIngestService;
use SyncBridge\Support\Csrf;
use SyncBridge\Support\Database;
use SyncBridge\Support\Env;
use SyncBridge\Support\Json;
use SyncBridge\Support\RateLimiter;
use SyncBridge\Support\Uuid;
use SyncBridge\Support\View;

require dirname(__DIR__) . '/bootstrap.php';

header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (!isset($_SESSION['demo_session_id']) || !is_string($_SESSION['demo_session_id'])) {
    $_SESSION['demo_session_id'] = Uuid::v4();
}
$demoSessionId = $_SESSION['demo_session_id'];

$worker = static function (int $limit, string $workerId, ?string $sessionId = null): array {
    return (new EventWorker(
        new WorkerRepository((new Database())->connection()),
        new DemoErpAdapter()
    ))->run($limit, $workerId, $sessionId);
};

try {
    if ($method === 'GET' && $path === '/health') {
        (new Database())->connection()->query('SELECT 1');
        Json::respond(['status' => 'ok', 'database' => 'connected']);
    }

    if ($method === 'GET' && $path === '/') {
        $queries = new EventQueryRepository((new Database())->connection());
        View::render('dashboard', [
            'title' => 'Dashboard',
            'counts' => $queries->counts($demoSessionId),
            'events' => $queries->recent($demoSessionId),
        ]);
    }

    if ($method === 'GET' && preg_match('#^/events/([0-9a-f-]{36})$#i', $path, $matches) === 1) {
        $queries = new EventQueryRepository((new Database())->connection());
        $detail = $queries->detail($matches[1], $demoSessionId);
        if ($detail === null) {
            http_response_code(404);
            View::render('error', ['title' => 'Evento non trovato', 'heading' => 'Evento non disponibile', 'message' => 'L’evento non esiste oppure appartiene a un’altra sessione demo.']);
        }
        View::render('event', ['title' => 'Dettaglio evento', 'detail' => $detail]);
    }

    if ($method === 'POST' && $path === '/demo/prepare') {
        $pdo = (new Database())->connection();
        if (!(new RateLimiter($pdo))->consume('demo_prepare', 20, 60)) {
            Json::respond(['error' => 'Limite temporaneo raggiunto. Attendi un minuto e riprova.'], 429);
        }
        $input = Json::decodeObject(file_get_contents('php://input') ?: '{}');
        if (!Csrf::isValid(isset($input['csrf']) && is_string($input['csrf']) ? $input['csrf'] : null)) {
            Json::respond(['error' => 'Sessione scaduta. Ricarica la pagina e riprova.'], 419);
        }

        $payload = (new DemoPayloadFactory())->make(
            is_string($input['event_type'] ?? null) ? $input['event_type'] : '',
            is_string($input['scenario'] ?? null) ? $input['scenario'] : ''
        );
        $rawBody = Json::encode($payload);
        $timestamp = time();
        $signer = new WebhookSigner(Env::get('WEBHOOK_SECRET'), Env::int('WEBHOOK_TOLERANCE_SECONDS', 300));

        Json::respond([
            'body' => $rawBody,
            'timestamp' => (string) $timestamp,
            'signature' => $signer->sign($rawBody, $timestamp),
            'demo_session_id' => $demoSessionId,
        ]);
    }

    if ($method === 'POST' && $path === '/api/webhooks/shop') {
        $pdo = (new Database())->connection();
        if (!(new RateLimiter($pdo))->consume('webhook_shop', 60, 60)) {
            Json::respond(['error' => 'Troppe richieste. Riprova più tardi.'], 429);
        }
        $rawBody = file_get_contents('php://input') ?: '';
        $service = new WebhookIngestService(
            new WebhookSigner(Env::get('WEBHOOK_SECRET'), Env::int('WEBHOOK_TOLERANCE_SECONDS', 300)),
            new EventRepository($pdo)
        );
        $result = $service->ingest(
            $rawBody,
            $_SERVER['HTTP_X_SYNCBRIDGE_TIMESTAMP'] ?? '',
            $_SERVER['HTTP_X_SYNCBRIDGE_SIGNATURE'] ?? '',
            $_SERVER['HTTP_X_SYNCBRIDGE_DEMO_SESSION'] ?? null
        );
        Json::respond($result, $result['duplicate'] ? 200 : 202);
    }

    if ($method === 'POST' && $path === '/demo/process') {
        $input = Json::decodeObject(file_get_contents('php://input') ?: '{}');
        if (!Csrf::isValid(isset($input['csrf']) && is_string($input['csrf']) ? $input['csrf'] : null)) {
            Json::respond(['error' => 'Sessione scaduta. Ricarica la pagina e riprova.'], 419);
        }
        Json::respond($worker(1, 'public-demo', $demoSessionId));
    }

    if ($method === 'POST' && preg_match('#^/events/([0-9a-f-]{36})/(retry-now|replay)$#i', $path, $matches) === 1) {
        if (!Csrf::isValid(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
            http_response_code(419);
            View::render('error', ['title' => 'Sessione scaduta', 'heading' => 'Sessione scaduta', 'message' => 'Ricarica la pagina e riprova.']);
        }
        $queries = new EventQueryRepository((new Database())->connection());
        $accepted = $matches[2] === 'retry-now'
            ? $queries->retryNow($matches[1], $demoSessionId)
            : $queries->replay($matches[1], $demoSessionId);
        if (!$accepted) {
            http_response_code(409);
            View::render('error', ['title' => 'Operazione non disponibile', 'heading' => 'Stato non compatibile', 'message' => 'L’evento non può essere riprocessato nello stato attuale.']);
        }

        $worker(1, 'manual-recovery', $demoSessionId);
        $_SESSION['_flash'] = ['type' => 'success', 'message' => $matches[2] === 'retry-now' ? 'Retry eseguito e registrato.' : 'Evento riprodotto e registrato.'];
        header('Location: /events/' . $matches[1], true, 303);
        exit;
    }

    if ($method === 'POST' && $path === '/cron/process') {
        $expected = Env::get('CRON_TOKEN');
        $provided = $_SERVER['HTTP_X_SYNCBRIDGE_CRON_TOKEN'] ?? '';
        if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
            Json::respond(['error' => 'Accesso non autorizzato.'], 401);
        }
        Json::respond($worker(Env::int('WORKER_BATCH_SIZE', 10), 'http-cron'));
    }

    http_response_code(404);
    View::render('error', ['title' => 'Pagina non trovata', 'heading' => 'Pagina non trovata', 'message' => 'La risorsa richiesta non è disponibile.']);
} catch (\JsonException | \InvalidArgumentException | \LengthException $exception) {
    if (str_starts_with($path, '/api/') || str_starts_with($path, '/demo/')) {
        Json::respond(['error' => $exception->getMessage()], 422);
    }
    http_response_code(422);
    View::render('error', ['title' => 'Richiesta non valida', 'heading' => 'Richiesta non valida', 'message' => $exception->getMessage()]);
} catch (\UnexpectedValueException $exception) {
    Json::respond(['error' => $exception->getMessage()], 401);
} catch (\Throwable $exception) {
    error_log('SyncBridge request failed: ' . $exception);
    if (str_starts_with($path, '/api/') || str_starts_with($path, '/demo/') || str_starts_with($path, '/cron/')) {
        Json::respond(['error' => 'Operazione non completata. Riprova più tardi.'], 500);
    }
    http_response_code(500);
    View::render('error', ['title' => 'Operazione non completata', 'heading' => 'Operazione non completata', 'message' => 'Il servizio non è momentaneamente disponibile. Riprova più tardi.']);
}
