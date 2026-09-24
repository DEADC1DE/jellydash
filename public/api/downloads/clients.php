<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Downloads\DownloadAccess;
use Mk\Framework\Downloads\ManagementService;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers;
use Mk\Framework\Downloads\TestReceipts;
use Mk\Framework\Integrations\ConnectionInput;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 3));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $authorization = new Authorization();
    if (!DownloadAccess::canManage($authorization)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to manage download clients.']);
        return;
    }
    $actor = (int) ($authorization->verifiedUser()['id'] ?? 0);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'Method not allowed.']);
        return;
    }
    $connections = new ConnectionRepository();
    if ($method === 'GET') {
        session_write_close();
        echo json_encode(['clients' => array_map(static fn ($client): array => $client->managementData(), $connections->all())], JSON_THROW_ON_ERROR);
        return;
    }
    if (!Csrf::validateHeader()) {
        http_response_code(419);
        echo json_encode(['error' => 'Your session token expired. Refresh the page and try again.']);
        return;
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new \RuntimeException('Could not read the client request.');
    }
    try {
        $raw = stream_get_contents($stream, 32769);
    } finally {
        fclose($stream);
    }
    if (!is_string($raw)) {
        throw new \RuntimeException('Could not read the client request.');
    }
    if (strlen($raw) > 32768) {
        throw new \LengthException('Client request is too large.');
    }
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) {
        throw new \InvalidArgumentException('Expected a client request object.');
    }
    $action = $input['action'] ?? null;
    $service = new ManagementService($connections);
    if ($action === 'test') {
        TestReceipts::reserve($_SESSION, time());
        $prepared = $service->prepare($input);
        session_write_close();
        $result = Providers::get($prepared['connection']->provider)->testConnection($prepared['connection'], $prepared['credentials']);
        session_start();
        $currentAuthorization = new Authorization();
        if (!DownloadAccess::canManage($currentAuthorization) || (int) ($currentAuthorization->verifiedUser()['id'] ?? 0) !== $actor) {
            http_response_code(403);
            echo json_encode(['error' => 'Your access changed. Refresh the page before saving this connection.']);
            return;
        }
        $receipt = TestReceipts::issue($_SESSION, $prepared['connection'], $prepared['credentials'], $actor, time());
        session_write_close();
        echo json_encode(['ok' => true, 'receipt' => $receipt] + $result, JSON_THROW_ON_ERROR);
        return;
    }
    if ($action === 'save') {
        $prepared = $service->prepare($input);
        if ($prepared['test_required'] && (!is_string($input['receipt'] ?? null)
            || !TestReceipts::consume($_SESSION, $input['receipt'], $prepared['connection'], $prepared['credentials'], $actor, time()))) {
            throw new \InvalidArgumentException('Test the connection before saving these changes.');
        }
        $saved = $connections->save($prepared['connection'], $prepared['secret'], $prepared['existing']?->revision);
        session_write_close();
        echo json_encode(['ok' => true, 'client' => $saved->managementData()], JSON_THROW_ON_ERROR);
        return;
    }
    if ($action === 'toggle') {
        $existing = $service->existing($input);
        if (!is_bool($input['enabled'] ?? null)) {
            throw new \InvalidArgumentException('Invalid client enabled setting.');
        }
        $data = $existing->managementData();
        $data['enabled'] = $input['enabled'];
        $saved = $connections->save(ConnectionInput::parse($data, $existing), null, $existing->revision);
        session_write_close();
        echo json_encode(['ok' => true, 'client' => $saved->managementData()], JSON_THROW_ON_ERROR);
        return;
    }
    if ($action === 'remove') {
        $existing = $service->existing($input);
        $connections->delete($existing->id, $existing->revision);
        session_write_close();
        echo json_encode(['ok' => true]);
        return;
    }
    throw new \InvalidArgumentException('Unknown client action.');
} catch (\OverflowException $error) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => $error->getMessage()]);
} catch (\LengthException $error) {
    http_response_code(413);
    echo json_encode(['error' => 'Client request is too large.']);
} catch (\JsonException $error) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid client request.']);
} catch (\InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (\DomainException $error) {
    http_response_code(409);
    echo json_encode(['error' => $error->getMessage()]);
} catch (ProviderException $error) {
    http_response_code(502);
    echo json_encode(['error' => $error->getMessage()]);
} catch (\Throwable $error) {
    http_response_code(500);
    Log::logException(new \RuntimeException('Download client management failed. Check the database and persistent integration key.'));
    echo json_encode(['error' => 'Could not save or unlock the client. Check the database and persistent integration key.']);
}
