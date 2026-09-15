<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\AuthManager;
use App\Csrf;
use App\Session;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Csrf::validate(is_string($csrfHeader) ? $csrfHeader : null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$auth = new AuthManager();
$user = $auth->currentUser();

if ($user === false) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);

if (!is_array($payload) || empty($payload['confirm'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirmation required']);
    exit;
}

$result = $auth->deleteCurrentAccount();

if (!$result['ok']) {
    $error = $result['error'] ?? 'auth.error.generic';
    http_response_code($error === 'auth.error.delete_blocked' ? 409 : 500);
    echo json_encode(['success' => false, 'message' => __($error)], JSON_UNESCAPED_UNICODE);
    exit;
}

Session::destroy();

echo json_encode(['success' => true, 'redirect' => 'index.php']);
