<?php
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../macros.php';
require_once __DIR__ . '/../fb_capi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$clickid = trim((string)($_POST['clickid'] ?? ''));
$eventName = trim((string)($_POST['event'] ?? ''));
$valueRaw = $_POST['value'] ?? 1;

if ($clickid === '' || $eventName === '' || !preg_match('/^[a-z0-9_]+$/', $eventName) || !is_numeric((string)$valueRaw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event payload']);
    exit;
}

$value = (float)$valueRaw;
if (!is_finite($value) || $value == 0.0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event value']);
    exit;
}

global $db;
$saved = $db->add_click_event($clickid, $eventName, $value);
if (!$saved) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save event']);
    exit;
}

// Fire Facebook offline conversions for fb_offline postbacks that list this
// browser event (e.g. the extension INSTALL). Delegated to the shared helper in
// fb_capi.php (also used by api/match.php). Fully defensive: any failure here
// must NEVER break the 200 response to the extension. Value sent is the click's
// payout, preserving this endpoint's original behavior.
try {
    $eventClick = $db->get_click_by_clickid($clickid);
    if (!empty($eventClick)) {
        fire_fb_offline_for_click($eventClick, $eventName, (float)($eventClick['payout'] ?? 0), $db);
    }
} catch (\Throwable $e) {
    add_log('postback', 'fb_offline events.php hook failed for clickid ' . $clickid . ': ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
