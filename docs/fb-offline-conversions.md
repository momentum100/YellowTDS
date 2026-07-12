# Facebook Offline / Conversions API (CAPI) Postback

Server-to-server (S2S) postback branch that reports conversions to Facebook's
[Conversions API](https://developers.facebook.com/docs/marketing-api/conversions-api)
(the "FB offline pixel"). It is **account-agnostic**: the Facebook credentials
travel with the configuration (or with the click), so a single TDS install can
serve many different ad accounts without any per-account server config.

## What the branch does

An `S2sPostback` of `type === 'fb_offline'` sends one CAPI event to
`https://graph.facebook.com/v21.0/{pixel_id}/events` when a matching conversion
happens. It is implemented in `fb_capi.php` (class `FbOfflineConversion`) and is
fired from two places:

1. **`api/postback.php`** — network/advertiser postbacks (`Lead`, `Purchase`,
   `Reject`, `Trash`). Fires when the postback `status` maps to an internal
   status listed in the postback's `events`.
2. **`api/events.php`** — the browser-extension **INSTALL** event. When an
   `event` arrives whose name is listed in a `fb_offline` postback's `events`,
   the same `FbOfflineConversion::send()` is fired. Facebook failures here are
   swallowed so the 200 response to the extension is never broken.

## Flow: click -> install -> conversion -> Facebook

```
Ad click (carries fbclid, optionally fbcreds)
   -> TDS records a `clicks` row: time, ip, ua, clickid, campaign_id, params(JSON)
        params contains fbclid (and optionally the base64 creds blob)
   -> User installs the extension  -> POST /api/events.php (event=install)
        -> add_click_event(...)   -> fire fb_offline postbacks matching "install"
   -> Network fires conversion     -> POST /api/postback.php (status=..., payout=...)
        -> update_status(...)      -> process_s2s_posbacks(...)
             -> fb_offline branch  -> FbOfflineConversion::send(...)
                  -> POST graph.facebook.com/v21.0/{pixel_id}/events
```

The match key sent to Facebook is **`fbc`**, deterministically rebuilt from the
captured `fbclid` and the click's unix `time`:

```
fbc = "fb.1." + <click unix time> + "." + <fbclid>
```

IP address (`client_ip_address`) and User-Agent (`client_user_agent`) are sent
as **SUPPORTING** signals only. They are never the sole match key — if `fbclid`
is missing, `fbc` is omitted and the event still ships with ip/ua so Facebook can
attempt a lower-confidence match.

## `S2sPostback` config fields

`S2sPostback` gained optional, backward-compatible fields (old `url` postbacks
keep working unchanged because every new field has a default):

| field           | default     | meaning                                                        |
|-----------------|-------------|----------------------------------------------------------------|
| `url`           | (required*) | url postback endpoint; `''`/unused for `fb_offline`            |
| `method`        | (existing)  | `GET`/`POST` for url postbacks                                 |
| `events`        | (existing)  | internal statuses / event names that trigger this postback     |
| `type`          | `'url'`     | `'url'` (legacy) or `'fb_offline'`                              |
| `creds`         | `''`        | base64 credential blob, or a macro like `{c.fbcreds}`          |
| `eventName`     | `''`        | FB `event_name` override; falls back to the internal status    |
| `actionSource`  | `'website'` | FB `action_source`                                             |
| `testEventCode` | `''`        | FB Test Events code; omitted from payload when empty           |
| `matchLogic`    | `'or'`      | `'or'` (fire if the current event is listed) or `'and'` (fire only when *every* listed event has occurred) |

\* `url` is only meaningful for `type==='url'`; the `fb_offline` branch ignores it.

`customEvents` is a **UI-only** field (not stored on the model): a comma-separated
free-text list of custom event names. At load time `S2sPostback::fromArray`
folds it into `events` as a union — see *Events: statuses ∪ custom events* below.

Example config fragment:

```json
{
  "type": "fb_offline",
  "url": "",
  "method": "POST",
  "events": ["Purchase", "install"],
  "creds": "{c.fbcreds}",
  "eventName": "Purchase",
  "actionSource": "website",
  "testEventCode": ""
}
```

## Admin UI: one list, per-entry type

Facebook is **not a separate section** — it is just another entry in the same
S2S list, distinguished by its `type`. Each entry in `#s2s_container` has:

- a **type** selector (`postback.s2s[i][type]`): `url` ("S2S URL", default) or
  `fb_offline` ("FB Offline Conversion"). Switching it (JS delegated on
  `#s2s_container`, so it works on rows added via **+ Add** clones too) shows the
  `url`+`method` fields for `url`, or the `creds`/`eventName`/`actionSource`/
  `testEventCode` fields for `fb_offline`.
- the status checkboxes (`Lead`/`Purchase`/`Reject`/`Trash`) **plus** a free-text
  `customEvents` input, and a `matchLogic` (OR/AND) selector — all shown for both
  types.

### Reporting to BOTH a partner network AND Facebook

Because FB is just another list entry, sending a conversion to your affiliate
network **and** to Facebook is simply **two entries** listening on the same
event: one `type=url` entry (the network postback) and one `type=fb_offline`
entry (the CAPI event), both with e.g. `Purchase` checked. The fire loop in
`process_s2s_posbacks` iterates every entry, so each fires independently.

## Events: statuses ∪ custom events

An entry's effective `events` list is the **union** of two inputs:

1. the checked network statuses (`Lead`/`Purchase`/`Reject`/`Trash`), and
2. the parsed `customEvents` text (comma-separated, trimmed, empties dropped,
   deduped) — e.g. `install,subscribe`.

The union is computed **server-side at load** in `S2sPostback::fromArray` (the
save path just stores the raw form JSON via `mergeSettingsRecursive`, so folding
must happen on load). Consequences:

- The runtime trigger logic (`api/postback.php`, `api/events.php`) and the admin
  re-render both see one unified list.
- On re-render, the status checkboxes reflect the known statuses in `events`, and
  the `customEvents` input is reconstructed from `events` **minus** the four known
  statuses. This round-trips losslessly.

## Trigger logic: OR vs AND (`matchLogic`)

`s2s_should_fire()` in `fb_capi.php` is the single shared gate used by **both**
call sites (network postback and browser event), so semantics stay identical:

- **OR** (default, = legacy behavior): fire iff the just-occurred event is in the
  entry's `events` list — `in_array($currentEvent, $entryEvents, true)`.
- **AND**: fire iff the current event is listed **and every OTHER** listed event
  has also occurred for this click. "Occurred" is detected as:
  - a **network status** (`Lead`/`Purchase`/`Reject`/`Trash`) — occurred iff it
    equals the click's latest `clicks.status` (`$click['status']`);
  - a **custom event** — occurred iff present in the click's recorded events map
    (`clicks.events`, already decoded onto `$click['events']` by
    `get_click_by_clickid`).
  - The just-fired current event always counts as occurred.

The `current event` passed in is the mapped internal status (`$inner_status`) in
`api/postback.php`, and the browser event name (`$eventName`) in `api/events.php`.

### AND limitation (important)

`clicks.status` stores only the **latest** network status for a click — each
network postback overwrites it via `update_status`. Therefore:

- AND is **reliable across custom events** (each custom event is retained
  independently in the `clicks.events` map, so several can be "occurred" at once),
  and for a single network status combined with custom events.
- AND is **limited across multiple network statuses**: an entry listing e.g.
  `["Lead","Purchase"]` with `matchLogic=and` can only ever see the most recent of
  the two as `clicks.status`, so it effectively won't satisfy both simultaneously
  (except the one currently firing). Prefer AND for custom-event gating (e.g.
  "only fire FB `Purchase` if `install` also happened"), not for chaining two
  network statuses.

## Credential format: `base64("<pixel_id>:<access_token>")`

Credentials are a single base64 string of `"<pixel_id>:<access_token>"`, e.g.
`base64("123456789:EAAG...token")`. This lets a click carry its own ad-account
credentials so the server needs no per-account config.

Ways to supply it:

0. **Via the admin UI (default, 1 ad account per campaign)** — the postback editor
   shows two separate fields, **FB Pixel ID** and **FB Access token**
   (`postback.s2s[i][pixelId]` / `[accessToken]`). `S2sPostback::fromArray` assembles
   `creds = base64("<pixelId>:<accessToken>")` on the fly, so the runtime sender is
   unchanged. On render, a legacy stored `creds` blob is split back into the two
   fields. This is the normal path.
1. **Via campaign config** — put the base64 blob directly in the postback's
   `creds` field (used when the two UI fields are empty).
2. **Via a click param carried in the ad URL** — set `creds` to a macro like
   `{c.fbcreds}`. The ad landing URL then carries `?fbcreds=<base64blob>`, it is
   stored in the click `params`, and `process_s2s_posbacks` resolves the macro
   through the existing `MacrosProcessor` at send time. (Account-agnostic mode; not
   exposed in the UI since the switch to two explicit fields.)

`decodeCreds()` splits the blob on the **first** `:` (tokens may themselves
contain no `:`, but splitting on the first colon is robust), and returns
`['pixel_id' => ..., 'access_token' => ...]`, or `null` for malformed input
(not base64, missing colon, or an empty half).

## FB event payload

`buildPayload()` produces one event object; `send()` wraps it in `data:[ ... ]`:

```json
{
  "event_name": "Purchase",
  "event_time": 1700000000,
  "action_source": "website",
  "event_id": "<clickid>",
  "user_data": {
    "fbc": "fb.1.<click time>.<fbclid>",
    "client_ip_address": "<click ip>",
    "client_user_agent": "<click ua>"
  },
  "custom_data": {
    "value": 12.5,
    "currency": "USD"
  }
}
```

- `event_time` is `time()` at send.
- `event_id` is the `clickid` (or an explicit override) — Facebook uses it for
  deduplication against any browser-side pixel event.
- `fbc` is omitted when no `fbclid` was captured.
- Optional hashed identifiers: if `$click['leaddata']` contains `em` (email) or
  `ph` (phone), they are added to `user_data` as lowercased, trimmed,
  **SHA-256** hashed `em`/`ph`. These are optional and never required.

The HTTP body is `application/x-www-form-urlencoded`:

```
data=<json-encoded [payload]>&access_token=<token>[&test_event_code=<code>]
```

### Dedicated clean curl (do NOT reuse requestfunc.php)

`send()` uses its own curl call, **not** `requestfunc.php`'s `get()`/`post()`.
Those helpers inject IP-spoofing headers (`X-Forwarded-For`, `CF-Connecting-IP`,
`X-Real-IP`, ...) via `get_request_headers()` and pass an array to
`CURLOPT_POSTFIELDS` (multipart). Sending spoofed client-IP headers to
`graph.facebook.com` is both wrong (they are TDS visitor IPs, not ours) and a
data-leak risk, and multipart is not what CAPI expects. The dedicated curl sends
only `Content-Type: application/x-www-form-urlencoded`, a 10s timeout, and a
form-urlencoded body — no spoof headers.

## Install matching by IP+UA (`api/match.php`)

A browser extension can only see the client's **IP + User-Agent** when it phones
home on install — it has **no `clickid`/subs**. `api/match.php` is a
**server-to-server** endpoint the extension's OWN backend calls to (a) find the
original ad click by IP+UA, (b) fire the Facebook offline conversion for it, and
(c) return the matched `clickid` so the backend can thread it forward.

### Call contract

```
GET|POST /api/match.php?apikey=<campaign apikey>&ip=<client ip>&ua=<client ua>&event=install&value=1&window=259200
```

| param    | required | default                    | notes                                                                 |
|----------|----------|----------------------------|-----------------------------------------------------------------------|
| `apikey` | **yes**  | —                          | the campaign's api key (`settings.apikey`); scopes + authorizes the lookup |
| `ip`     | no       | `$_SERVER['REMOTE_ADDR']`  | the CLIENT's ip; the caller SHOULD pass it explicitly (S2S call, so `REMOTE_ADDR` is the caller's server, not the client) |
| `ua`     | no       | `$_SERVER['HTTP_USER_AGENT']` | the CLIENT's user-agent; likewise pass explicitly                  |
| `event`  | **yes**  | —                          | browser event name, validated `^[a-z0-9_]+$` (e.g. `install`)         |
| `value`  | no       | `1`                        | conversion value; must be numeric and `> 0`                          |
| `window` | no       | `259200` (72h)             | look-back seconds; clamped to `[300, 1209600]` (5min .. 14 days)     |

### Matching rule

Exact `ip` **AND** exact `ua`, `time >= now - window`, **scoped to the campaign
resolved from `apikey`** (`clicks.campaign_id`), take the **most recent** click
(`ORDER BY time DESC LIMIT 1`). A second `COUNT(DISTINCT clickid)` over the same
WHERE yields `ambiguous_count`: when more than one distinct clickid matches the
window we still take the most recent, but surface the count in the response and
`add_log('match', ...)` it.

`apikey` is **mandatory** — it both authorizes the call (a missing/invalid key is
rejected `400`/`403` JSON) and narrows the IP+UA search to one campaign, which
prevents abuse (firing conversions for arbitrary ip+ua across every campaign) and
sharpens match precision.

### JSON response

No match (still HTTP 200):

```json
{ "matched": false }
```

Match:

```json
{
  "matched": true,
  "clickid": "<clickid>",
  "ambiguous_count": 1,
  "event_saved": true,
  "fb": [ { "event": "Purchase", "http_code": 200, "error": null } ]
}
```

- `ambiguous_count` = distinct clickids that matched the window (`1` = clean, `>1`
  = ambiguous, logged).
- `event_saved` = whether `add_click_event()` persisted the event.
- `fb` = per-`fb_offline`-postback send results (empty array if none fired).
- Errors are returned as `{"error": "..."}` with a `4xx`/`5xx` status — never a
  raw 500 stack. FB/DB failures degrade to a clear JSON error.

### CAVEATS (read before relying on this)

IP+UA attribution is **inherently lossy** — treat it as best-effort, not ground
truth:

- **IP is not a unique identifier.** NAT / CGNAT (carrier-grade NAT on mobile),
  corporate/office egress, public Wi-Fi and shared VPN exit nodes put many
  distinct users behind ONE ip. Combined with a common UA this yields **false
  matches** (the conversion is attributed to the wrong click). Mobile IPs also
  **churn** (the install can arrive from a different ip than the original click),
  causing **misses**.
- **Exact-UA misses on browser updates.** The UA string changes when Chrome/Edge
  auto-updates between the click and the install; an exact compare then fails to
  match a click that is genuinely the same user.
- **Ambiguity is real.** When `ambiguous_count > 1` we pick the most-recent click,
  which is a heuristic — it can attribute to the wrong one. The count is logged so
  you can measure how often this happens for a campaign.
- **The window is a trade-off.** A longer `window` recovers slow installs but
  increases the chance of colliding with an unrelated newer click on the same
  ip+ua; a shorter one is precise but misses delayed installs.

**Better alternative:** thread the real `clickid` through the post-install
welcome tab. When the extension opens its welcome/onboarding page after install,
include the `clickid` (captured at click time and carried into the extension, or
minted on the landing page) as a URL param, and have that page call
`api/events.php` with the exact `clickid`. That is a deterministic 1:1
attribution and should be preferred wherever the extension flow allows it;
`api/match.php` is the fallback for when only ip+ua are available.

## SECURITY

- **base64 is NOT encryption.** `base64("pixel:token")` is trivially reversible
  by anyone. It is used here only as a compact transport encoding.
- **Putting an `access_token` in a public ad URL exposes it.** Anyone who sees
  the ad URL (or browser history, referrer logs, proxies) can decode the blob
  and obtain the Facebook access token for that ad account. Prefer supplying
  `creds` through campaign config rather than a public `{c.fbcreds}` param, and
  scope/rotate tokens accordingly.
- **`decodeCreds()` is the security seam.** It is intentionally the single,
  isolated place that turns the transported blob into `{pixel_id, access_token}`.
  To harden this, swap the body of `decodeCreds()` for:
  - real decryption (e.g. AES-GCM with a server-held key), or
  - a **server-side alias map** — the click carries only an opaque alias id and
    `decodeCreds()` looks up the real pixel/token server-side, so the token never
    travels in the URL at all.
  No caller needs to change; only `decodeCreds()` does.
