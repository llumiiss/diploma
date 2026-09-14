<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\AuthManager;
use App\Csrf;
use App\ManagerSubscriptionManager;

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

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$nazwaUslugi = trim((string) ($payload['nazwa_uslugi'] ?? ''));
$mailSubskrypcji = trim((string) ($payload['mail_subskrypcji'] ?? ''));
$usernameKonta = trim((string) ($payload['username_konta'] ?? ''));
$kosztPln = $payload['koszt_pln'] ?? null;
$dataPlatnosci = trim((string) ($payload['data_nastepnej_platnosci'] ?? ''));

if ($nazwaUslugi === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'nazwa_uslugi is required']);
    exit;
}

if ($dataPlatnosci === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPlatnosci)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'data_nastepnej_platnosci must be YYYY-MM-DD']);
    exit;
}

if ($mailSubskrypcji !== '' && !filter_var($mailSubskrypcji, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid mail_subskrypcji']);
    exit;
}

if (!is_numeric($kosztPln) || (float) $kosztPln < 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'koszt_pln must be a non-negative number']);
    exit;
}

$manager = new ManagerSubscriptionManager();
$subscription = $manager->create((int) $user['id'], [
    'nazwa_uslugi'             => $nazwaUslugi,
    'mail_subskrypcji'         => $mailSubskrypcji,
    'username_konta'           => $usernameKonta,
    'koszt_pln'                => round((float) $kosztPln, 2),
    'data_nastepnej_platnosci' => $dataPlatnosci,
]);

echo json_encode([
    'success'      => true,
    'subscription' => $subscription,
], JSON_UNESCAPED_UNICODE);
