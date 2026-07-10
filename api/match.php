<?php
/**
 * api/match.php — attribute an extension install to the original ad click by
 * EXACT ip + EXACT ua (server-to-server), fire the Facebook offline conversion,
 * and return the matched clickid.
 *
 * The extension's OWN backend calls this: on install it has the client's ip + ua
 * but no clickid/subs. We resolve the campaign from a required apikey, look up the
 * most-recent matching click scoped to that campaign inside a look-back window,
 * record the event, fire fb_offline conversions, and return the clickid.
 *
 * See docs/fb-offline-conversions.md — "Install matching by IP+UA" — for the full
 * contract and the (important) IP+UA attribution caveats.
 */

require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../macros.php';
require_once __DIR__ . '/../fb_capi.php';

header('Content-Type: application/json');

/**
 * Emit a JSON error with an HTTP status and stop. Never leaks a stack trace.
 */
function match_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

try {
    // GET or POST. Merge both bags; POST wins on key collision.
    $src = array_merge($_GET, $_POST);

    $params = match_normalize_params($src, $_SERVER);
    if (!$params['ok']) {
        match_fail(400, (string)($params['error'] ?? 'Invalid request'));
    }

    global $db;

    // Resolve + authorize via apikey; scope the lookup to this campaign only.
    $campaign = $db->get_campaign_by_apikey($params['apikey']);
    if (empty($campaign) || !isset($campaign['id'])) {
        match_fail(403, 'Invalid apikey');
    }
    $campaignId = (int)$campaign['id'];

    $sinceTs = time() - $params['window'];
    $match = $db->find_click_by_ip_ua($params['ip'], $params['ua'], $sinceTs, $campaignId);
    $click = $match['click'] ?? [];
    $distinct = (int)($match['distinct_count'] ?? 0);

    if (empty($click)) {
        echo json_encode(['matched' => false]);
        exit;
    }

    $clickid = (string)($click['clickid'] ?? '');
    if ($clickid === '') {
        // A matched row with no clickid is unusable for attribution.
        match_fail(500, 'Matched click has no clickid');
    }

    if ($distinct > 1) {
        add_log('match', 'ambiguous ip+ua match for campaign ' . $campaignId
            . ', ip ' . $params['ip']
            . ', distinct_clickids ' . $distinct
            . ', chose most-recent clickid ' . $clickid
            . ', window ' . $params['window']);
    }

    // Record the browser event on the matched click.
    $eventSaved = false;
    try {
        $eventSaved = $db->add_click_event($clickid, $params['event'], $params['value']);
    } catch (\Throwable $e) {
        add_log('match', 'add_click_event failed for clickid ' . $clickid . ': ' . $e->getMessage());
    }

    // Fire fb_offline conversions (shared helper, fully defensive — never throws).
    $fb = fire_fb_offline_for_click($click, $params['event'], $params['value'], $db);

    echo json_encode([
        'matched' => true,
        'clickid' => $clickid,
        'ambiguous_count' => $distinct,
        'event_saved' => (bool)$eventSaved,
        'fb' => $fb,
    ]);
} catch (\Throwable $e) {
    add_log('match', 'api/match.php failed: ' . $e->getMessage());
    match_fail(500, 'Internal error');
}
