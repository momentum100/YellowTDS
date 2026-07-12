<?php
require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';

header('Content-Type: application/json; charset=utf-8');

$clickid = trim((string)($_GET['clickid'] ?? ''));
if ($clickid === '' || strlen($clickid) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid click ID']);
    exit;
}

global $db;
$click = $db->get_click_by_clickid($clickid);
if (empty($click)) {
    http_response_code(404);
    echo json_encode(['error' => 'Click not found']);
    exit;
}

echo json_encode(['click' => $click], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
