<?php
// Admin AJAX endpoint: fire a single Facebook Conversions API test event straight
// from the campaign settings page, using the pixel id + access token typed into a
// postback row. Auth is enforced by securitycheck.php (same pattern as
// domaincheck.php). A Test Event Code is REQUIRED so this never pollutes real
// optimization data — the event shows up under Events Manager → Test Events.

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../fb_capi.php';

header('Content-Type: application/json');

$pixelId       = trim((string)($_POST['pixelId'] ?? ''));
$accessToken   = trim((string)($_POST['accessToken'] ?? ''));
$testEventCode = trim((string)($_POST['testEventCode'] ?? ''));
$eventName     = trim((string)($_POST['eventName'] ?? ''));
$actionSource  = trim((string)($_POST['actionSource'] ?? ''));

if ($eventName === '') {
    $eventName = 'TestEvent';
}
if ($actionSource === '') {
    $actionSource = 'website';
}

if ($pixelId === '' || $accessToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Enter Pixel ID and Access token first.']);
    exit;
}
if ($testEventCode === '') {
    echo json_encode(['ok' => false, 'error' => 'Enter a Test event code (FB Events Manager → Test Events) so the test does not count as real data.']);
    exit;
}

$creds = base64_encode($pixelId . ':' . $accessToken);

// Minimal synthetic click: the admin's own IP/UA + a throwaway id. No fbclid, so
// fbc is omitted — fine for validating creds/connectivity via the Test Events tab.
$click = [
    'clickid' => 'test_' . bin2hex(random_bytes(6)),
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'time'    => time(),
    'params'  => [],
];

$res = FbOfflineConversion::send($creds, $click, $eventName, 1.0, 'USD', $actionSource, $testEventCode);

$httpCode = (int)($res['http_code'] ?? 0);
$body     = $res['body'] ?? '';
$ok       = ($httpCode >= 200 && $httpCode < 300);

echo json_encode([
    'ok'        => $ok,
    'http_code' => $httpCode,
    'response'  => is_string($body) ? $body : '',
    'error'     => $ok ? null : ($res['error'] ?? ('Facebook returned HTTP ' . $httpCode)),
]);
