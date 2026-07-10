<?php

require_once __DIR__ . '/logging.php';

/**
 * Resolve a creds field (literal base64 blob OR a macro like {c.fbcreds})
 * through the existing MacrosProcessor. replace_url_macros only rewrites query
 * values that are macros, so literal blobs pass through unchanged. Defined here
 * (not in postback.php) so both api/postback.php and api/events.php can use it.
 *
 * @param object $mp a MacrosProcessor (loose type: this file must lint/load
 *                   without requiring macros.php).
 */
function fb_resolve_creds($mp, string $creds): string
{
    $creds = trim($creds);
    if ($creds === '') {
        return '';
    }
    $resolved = $mp->replace_url_macros('http://x/?creds=' . rawurlencode($creds));
    $parts = parse_url($resolved);
    if (empty($parts['query'])) {
        return $creds;
    }
    parse_str($parts['query'], $q);
    return isset($q['creds']) ? (string)$q['creds'] : $creds;
}

/**
 * The four internal network statuses. Anything in an entry's events list that is
 * not one of these is treated as a custom (browser) event.
 */
const S2S_NETWORK_STATUSES = ['Lead', 'Purchase', 'Reject', 'Trash'];

/**
 * Normalize a click's recorded custom-events into an assoc map name => value.
 * get_click_by_clickid() already json_decodes clicks.events into an array, but
 * this also accepts a raw JSON string so the helper is robust in isolation
 * (and unit-testable without a DB).
 */
function s2s_recorded_events(array $click): array
{
    $events = $click['events'] ?? [];
    if (is_string($events) && $events !== '') {
        $decoded = json_decode($events, true);
        $events = is_array($decoded) ? $decoded : [];
    }
    return is_array($events) ? $events : [];
}

/**
 * Decide whether an s2s entry should fire for the event that just occurred.
 *
 * Shared by api/postback.php (current event = the mapped network status) and
 * api/events.php (current event = the browser event name), so the OR/AND
 * semantics stay identical in both call sites (DRY).
 *
 *  - OR  (default): fire iff $currentEvent is in $entryEvents.
 *  - AND: fire iff $currentEvent is in $entryEvents AND every OTHER event in the
 *         list has also occurred for this click. "Occurred" means:
 *           * a network status (Lead/Purchase/Reject/Trash) equals the click's
 *             latest recorded status ($click['status']);
 *           * a custom event is present in the click's recorded events map
 *             (clicks.events, already decoded onto $click['events']).
 *         The just-fired $currentEvent always counts as occurred.
 *
 * NOTE on the $db parameter: it is accepted for forward-compatibility / a
 * possible future fallback query, but is currently unused because both call
 * sites hand us a fully-loaded $click that already carries its decoded
 * `events` map and `status`.
 *
 * @param object|null $db unused (see note above).
 */
function s2s_should_fire(array $entryEvents, string $matchLogic, string $currentEvent, array $click, $db = null): bool
{
    $entryEvents = array_values(array_filter(
        array_map(static fn($e) => trim((string)$e), $entryEvents),
        static fn($e) => $e !== ''
    ));
    $currentEvent = trim($currentEvent);

    // Both modes require the current event to be listed on the entry.
    if (!in_array($currentEvent, $entryEvents, true)) {
        return false;
    }
    if (strtolower(trim($matchLogic)) !== 'and') {
        return true; // OR (default / legacy behavior)
    }

    // AND: every OTHER listed event must also have occurred for this click.
    $clickStatus = (string)($click['status'] ?? '');
    $recorded = s2s_recorded_events($click);

    foreach ($entryEvents as $ev) {
        if ($ev === $currentEvent) {
            continue; // the just-fired event counts as occurred
        }
        if (in_array($ev, S2S_NETWORK_STATUSES, true)) {
            // Network status: occurred iff it equals the click's latest status.
            // (clicks.status keeps only the most recent status, so AND across
            // multiple *network* statuses is inherently limited — see docs.)
            if ($ev !== $clickStatus) {
                return false;
            }
            continue;
        }
        // Custom event: occurred iff recorded on the click.
        if (!array_key_exists($ev, $recorded)) {
            return false;
        }
    }
    return true;
}

/**
 * Facebook Conversions API ("FB offline pixel") sender.
 *
 * Account-agnostic: credentials travel as a base64("<pixel_id>:<access_token>")
 * blob (in campaign config or carried with the click), so one install can serve
 * many ad accounts with no per-account server config.
 *
 * See docs/fb-offline-conversions.md for the full flow and the SECURITY notes.
 *
 * Methods are pure/static where possible so the logic is unit-testable without
 * touching the network; only send() performs I/O.
 */
class FbOfflineConversion
{
    const GRAPH_VERSION = 'v21.0';

    /**
     * SECURITY SEAM.
     *
     * This is the ONE place that turns the transported credential blob into
     * concrete {pixel_id, access_token}. base64 is NOT encryption — it is only a
     * transport encoding. To harden, replace the body of this method with real
     * decryption (e.g. AES-GCM with a server-held key) or a server-side alias
     * map (click carries an opaque id; the real token is looked up here and never
     * travels in the URL). No caller needs to change.
     *
     * @return array{pixel_id:string,access_token:string}|null null on bad input.
     */
    public static function decodeCreds(string $b64): ?array
    {
        $b64 = trim($b64);
        if ($b64 === '') {
            return null;
        }

        $decoded = base64_decode($b64, true); // strict
        if ($decoded === false || $decoded === '') {
            return null;
        }

        // Split on the FIRST colon only: pixel id has no colon, tokens might.
        $pos = strpos($decoded, ':');
        if ($pos === false) {
            return null;
        }

        $pixelId = substr($decoded, 0, $pos);
        $token = substr($decoded, $pos + 1);
        if ($pixelId === '' || $token === '') {
            return null;
        }

        return ['pixel_id' => $pixelId, 'access_token' => $token];
    }

    /**
     * Build the Facebook click id (fbc) from a captured fbclid.
     * Format: fb.1.<click unix time>.<fbclid>
     */
    public static function buildFbc(string $fbclid, int $clickTime): string
    {
        return 'fb.1.' . $clickTime . '.' . $fbclid;
    }

    /**
     * Build one Facebook CAPI event object (pure — no network).
     *
     * fbclid is pulled from $click['params']['fbclid']; params may be a JSON
     * string (not yet decoded) or an already-decoded array — both are handled.
     * IP/UA are SUPPORTING signals; fbc is the primary match key and is omitted
     * when no fbclid was captured (ip/ua still ship so a lower-confidence match
     * remains possible).
     *
     * @return array the event object (wrap in data:[...] before sending).
     */
    public static function buildPayload(
        array $click,
        string $eventName,
        float $value,
        string $currency,
        string $actionSource,
        ?string $eventId = null
    ): array {
        $params = self::normalizeAssoc($click['params'] ?? []);
        $fbclid = isset($params['fbclid']) ? (string)$params['fbclid'] : '';
        $clickTime = (int)($click['time'] ?? time());
        $clickid = (string)($click['clickid'] ?? '');

        $userData = [];
        if ($fbclid !== '') {
            $userData['fbc'] = self::buildFbc($fbclid, $clickTime);
        }
        if (!empty($click['ip'])) {
            $userData['client_ip_address'] = (string)$click['ip'];
        }
        if (!empty($click['ua'])) {
            $userData['client_user_agent'] = (string)$click['ua'];
        }

        // Optional hashed identifiers (em/ph) — never required.
        $userData = self::addHashedIdentifiers($userData, $click);

        return [
            'event_name' => $eventName,
            'event_time' => time(),
            'action_source' => $actionSource,
            'event_id' => $eventId ?? $clickid,
            'user_data' => $userData,
            'custom_data' => [
                'value' => $value,
                'currency' => $currency,
            ],
        ];
    }

    /**
     * Decode creds, build the payload and POST it to Facebook with a DEDICATED
     * clean curl. We deliberately do NOT reuse requestfunc.php get()/post(): those
     * inject visitor IP-spoofing headers (X-Forwarded-For, CF-Connecting-IP, ...)
     * and send multipart, neither of which is correct or safe for graph.facebook.com.
     *
     * @return array{http_code:int,body:?string,error:?string}
     */
    public static function send(
        string $credsB64,
        array $click,
        string $eventName,
        float $value,
        string $currency = 'USD',
        string $actionSource = 'website',
        ?string $testEventCode = null,
        string $proxy = ''
    ): array {
        $creds = self::decodeCreds($credsB64);
        if ($creds === null) {
            $msg = 'fb_offline: could not decode creds for clickid ' . (string)($click['clickid'] ?? '');
            add_log('postback', $msg);
            return ['http_code' => 0, 'body' => null, 'error' => 'bad_creds'];
        }

        $payload = self::buildPayload($click, $eventName, $value, $currency, $actionSource);

        $fields = [
            'data' => json_encode([$payload]),
            'access_token' => $creds['access_token'],
        ];
        if (!empty($testEventCode)) {
            $fields['test_event_code'] = $testEventCode;
        }

        $url = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/'
            . rawurlencode($creds['pixel_id']) . '/events';

        // Dedicated clean curl — form-urlencoded body, no IP-spoof headers.
        $curl = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ];
        // Facebook blocks direct datacenter IPs — route through the configured
        // proxy when set. The scheme in the string (e.g. socks5h://) is honored
        // by libcurl; socks5h resolves DNS through the proxy.
        $proxy = trim($proxy);
        if ($proxy !== '') {
            $opts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($curl, $opts);
        $body = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        curl_close($curl);

        $httpCode = (int)($info['http_code'] ?? 0);
        add_log('postback', 'fb_offline, ' . $eventName . ', pixel ' . $creds['pixel_id']
            . ', clickid ' . (string)($click['clickid'] ?? '')
            . ', http ' . $httpCode
            . ($error !== '' ? ', curl_error ' . $error : '')
            . ', resp ' . (is_string($body) ? substr($body, 0, 500) : ''));

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : null,
            'error' => $error !== '' ? $error : null,
        ];
    }

    /**
     * Hook: add SHA-256 hashed em/ph from $click['leaddata'] when present.
     * leaddata may be a JSON string or an array. Optional — no-op when absent.
     */
    private static function addHashedIdentifiers(array $userData, array $click): array
    {
        $lead = self::normalizeAssoc($click['leaddata'] ?? []);
        if (empty($lead)) {
            return $userData;
        }

        if (!empty($lead['em'])) {
            $email = strtolower(trim((string)$lead['em']));
            if ($email !== '') {
                $userData['em'] = hash('sha256', $email);
            }
        }
        if (!empty($lead['ph'])) {
            $phone = preg_replace('/\D+/', '', (string)$lead['ph']); // digits only
            if ($phone !== '') {
                $userData['ph'] = hash('sha256', $phone);
            }
        }

        return $userData;
    }

    /**
     * Normalize a field that may be a JSON string OR an already-decoded array
     * into an associative array. Anything else becomes [].
     */
    private static function normalizeAssoc($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}

/**
 * Look-back window bounds for api/match.php (seconds).
 * Default 72h; clamped to [5 min, 14 days] so a caller can neither ask for an
 * absurdly tiny nor an unbounded window.
 */
const MATCH_WINDOW_DEFAULT = 259200;   // 72h
const MATCH_WINDOW_MIN = 300;          // 5 min
const MATCH_WINDOW_MAX = 1209600;      // 14 days

/**
 * Validate + normalize the incoming request params for api/match.php.
 *
 * Pure (no DB, no network, no superglobals): $src is the merged GET/POST bag and
 * $server is (a slice of) $_SERVER, both injected so this is unit-testable. Kept
 * in fb_capi.php — the endpoint's only network-free piece — beside the other s2s
 * helpers so the test suite can load it without instantiating the DB.
 *
 * Rules:
 *  - apikey : required, non-empty (authorizes + scopes the lookup).
 *  - event  : required, must match ^[a-z0-9_]+$ (same rule add_click_event uses).
 *  - value  : default 1; must be numeric, finite and > 0.
 *  - window : default MATCH_WINDOW_DEFAULT; numeric values are clamped to
 *             [MATCH_WINDOW_MIN, MATCH_WINDOW_MAX]; non-numeric falls back to the
 *             default.
 *  - ip     : $src['ip'], else $server['REMOTE_ADDR']; required non-empty.
 *  - ua     : $src['ua'], else $server['HTTP_USER_AGENT']; required non-empty.
 *
 * @return array{ok:bool,error:?string,apikey:string,ip:string,ua:string,event:string,value:float,window:int}
 */
function match_normalize_params(array $src, array $server): array
{
    $out = [
        'ok' => false,
        'error' => null,
        'apikey' => '',
        'ip' => '',
        'ua' => '',
        'event' => '',
        'value' => 1.0,
        'window' => MATCH_WINDOW_DEFAULT,
    ];

    $apikey = trim((string)($src['apikey'] ?? ''));
    if ($apikey === '') {
        $out['error'] = 'Missing apikey';
        return $out;
    }
    $out['apikey'] = $apikey;

    $event = trim((string)($src['event'] ?? ''));
    if ($event === '' || !preg_match('/^[a-z0-9_]+$/', $event)) {
        $out['error'] = 'Invalid or missing event (must match ^[a-z0-9_]+$)';
        return $out;
    }
    $out['event'] = $event;

    // value: default 1, must be numeric, finite and > 0.
    $valueRaw = $src['value'] ?? 1;
    if (!is_numeric((string)$valueRaw)) {
        $out['error'] = 'Invalid value (must be numeric)';
        return $out;
    }
    $value = (float)$valueRaw;
    if (!is_finite($value) || $value <= 0) {
        $out['error'] = 'Invalid value (must be > 0)';
        return $out;
    }
    $out['value'] = $value;

    // window: default; clamp numeric to [MIN, MAX]; non-numeric -> default.
    $windowRaw = $src['window'] ?? null;
    if ($windowRaw !== null && $windowRaw !== '' && is_numeric((string)$windowRaw)) {
        $window = (int)$windowRaw;
        $window = max(MATCH_WINDOW_MIN, min(MATCH_WINDOW_MAX, $window));
        $out['window'] = $window;
    }

    // ip: explicit param, else the caller connection (mostly useless on an S2S
    // call, but a sane last resort). Required.
    $ip = trim((string)($src['ip'] ?? ''));
    if ($ip === '') {
        $ip = trim((string)($server['REMOTE_ADDR'] ?? ''));
    }
    if ($ip === '') {
        $out['error'] = 'Missing ip';
        return $out;
    }
    $out['ip'] = $ip;

    // ua: explicit param, else the request UA header. Not trimmed of internal
    // whitespace — exact match fidelity against the stored click ua matters.
    $ua = (string)($src['ua'] ?? '');
    if ($ua === '') {
        $ua = (string)($server['HTTP_USER_AGENT'] ?? '');
    }
    if ($ua === '') {
        $out['error'] = 'Missing ua';
        return $out;
    }
    $out['ua'] = $ua;

    $out['ok'] = true;
    return $out;
}

/**
 * Shared FB-offline fire loop, extracted from api/events.php so both that
 * endpoint and api/match.php fire conversions identically (DRY).
 *
 * Loads the campaign from $click['campaign_id'], iterates its s2sPostbacks, and
 * for every type==='fb_offline' entry that passes s2s_should_fire() resolves the
 * creds blob (via fb_resolve_creds + MacrosProcessor) and sends one CAPI event.
 *
 * $eventName is the just-occurred event used for s2s_should_fire matching (the
 * per-postback $s2s->eventName override, when set, is what actually ships as the
 * FB event_name). $value is the conversion value to send.
 *
 * FULLY DEFENSIVE: never throws. A bad campaign, a bad postback entry, or a send
 * failure is caught + logged and the loop continues; callers get whatever
 * per-postback results succeeded.
 *
 * Depends on Campaign + MacrosProcessor being already loaded by the caller
 * (events.php / match.php both require them). fb_capi.php intentionally does NOT
 * require campaign.php/macros.php so it stays standalone-loadable/testable, so we
 * guard on class_exists.
 *
 * @return array<int,array{event:string,http_code:int,error:?string}>
 */
function fire_fb_offline_for_click(array $click, string $eventName, float $value, $db): array
{
    $results = [];
    try {
        if (empty($click) || $db === null) {
            return $results;
        }
        if (!class_exists('Campaign') || !class_exists('MacrosProcessor')) {
            add_log('postback', 'fire_fb_offline_for_click: Campaign/MacrosProcessor not loaded');
            return $results;
        }

        $campaignId = (int)($click['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            return $results;
        }

        $cs = $db->get_campaign_settings($campaignId);
        if (empty($cs)) {
            return $results;
        }

        $campaign = new Campaign($campaignId, $cs);
        $mp = new MacrosProcessor(
            null,
            $click,
            (string)($click['clickid'] ?? ''),
            (string)($click['userid'] ?? '')
        );

        foreach ($campaign->postback->s2sPostbacks as $s2s) {
            try {
                if (($s2s->type ?? 'url') !== 'fb_offline') {
                    continue;
                }
                if (!s2s_should_fire($s2s->events, $s2s->matchLogic ?? 'or', $eventName, $click, $db)) {
                    continue;
                }
                $credsB64 = fb_resolve_creds($mp, $s2s->creds);
                if ($credsB64 === '') {
                    continue;
                }
                $fbEvent = ($s2s->eventName ?? '') !== '' ? $s2s->eventName : $eventName;
                $res = FbOfflineConversion::send(
                    $credsB64,
                    $click,
                    $fbEvent,
                    $value,
                    'USD',
                    ($s2s->actionSource ?? '') !== '' ? $s2s->actionSource : 'website',
                    ($s2s->testEventCode ?? '') !== '' ? $s2s->testEventCode : null,
                    (string)($s2s->proxy ?? '')
                );
                $results[] = [
                    'event' => $fbEvent,
                    'http_code' => (int)($res['http_code'] ?? 0),
                    'error' => $res['error'] ?? null,
                ];
            } catch (\Throwable $e) {
                add_log('postback', 'fire_fb_offline_for_click entry failed for clickid '
                    . (string)($click['clickid'] ?? '') . ': ' . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        add_log('postback', 'fire_fb_offline_for_click failed for clickid '
            . (string)($click['clickid'] ?? '') . ': ' . $e->getMessage());
    }
    return $results;
}
