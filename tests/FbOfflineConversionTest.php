<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../fb_capi.php';

class FbOfflineConversionTest extends TestCase
{
    public function testDecodeCredsSplitsPixelAndToken(): void
    {
        $blob = base64_encode('123456:TOKEN');
        $creds = FbOfflineConversion::decodeCreds($blob);

        $this->assertIsArray($creds);
        $this->assertSame('123456', $creds['pixel_id']);
        $this->assertSame('TOKEN', $creds['access_token']);
    }

    public function testDecodeCredsSplitsOnFirstColonOnly(): void
    {
        // access tokens may (rarely) contain colons; only the first splits.
        $blob = base64_encode('123456:TOK:EN:x');
        $creds = FbOfflineConversion::decodeCreds($blob);

        $this->assertSame('123456', $creds['pixel_id']);
        $this->assertSame('TOK:EN:x', $creds['access_token']);
    }

    public function testDecodeCredsRejectsMalformedInput(): void
    {
        $this->assertNull(FbOfflineConversion::decodeCreds(''));
        // no colon after decoding
        $this->assertNull(FbOfflineConversion::decodeCreds(base64_encode('nocolonhere')));
        // empty pixel id
        $this->assertNull(FbOfflineConversion::decodeCreds(base64_encode(':TOKEN')));
        // empty token
        $this->assertNull(FbOfflineConversion::decodeCreds(base64_encode('123456:')));
        // not valid base64 (strict)
        $this->assertNull(FbOfflineConversion::decodeCreds('!!!not base64!!!'));
    }

    public function testBuildFbc(): void
    {
        $this->assertSame('fb.1.1700000000.abc123', FbOfflineConversion::buildFbc('abc123', 1700000000));
    }

    public function testBuildPayloadFullStructure(): void
    {
        $click = [
            'clickid' => 'CLICK1',
            'time' => 1700000000,
            'ip' => '8.8.8.8',
            'ua' => 'Mozilla/5.0 Test',
            'params' => ['fbclid' => 'FBCLID_XYZ'],
        ];

        $payload = FbOfflineConversion::buildPayload($click, 'Purchase', 12.5, 'USD', 'website');

        $this->assertSame('Purchase', $payload['event_name']);
        $this->assertIsInt($payload['event_time']);
        $this->assertSame('website', $payload['action_source']);
        $this->assertSame('CLICK1', $payload['event_id']);

        $this->assertSame('fb.1.1700000000.FBCLID_XYZ', $payload['user_data']['fbc']);
        $this->assertSame('8.8.8.8', $payload['user_data']['client_ip_address']);
        $this->assertSame('Mozilla/5.0 Test', $payload['user_data']['client_user_agent']);

        $this->assertSame(12.5, $payload['custom_data']['value']);
        $this->assertSame('USD', $payload['custom_data']['currency']);
    }

    public function testBuildPayloadEventIdOverride(): void
    {
        $click = ['clickid' => 'CLICK1', 'time' => 1700000000, 'ip' => '1.2.3.4', 'ua' => 'UA', 'params' => []];
        $payload = FbOfflineConversion::buildPayload($click, 'Lead', 1.0, 'USD', 'website', 'OVERRIDE_ID');
        $this->assertSame('OVERRIDE_ID', $payload['event_id']);
    }

    public function testBuildPayloadParamsAsJsonString(): void
    {
        // params may arrive as a raw JSON string (not yet decoded).
        $click = [
            'clickid' => 'CLICK2',
            'time' => 1700000001,
            'ip' => '9.9.9.9',
            'ua' => 'UA2',
            'params' => json_encode(['fbclid' => 'FROMSTRING']),
        ];

        $payload = FbOfflineConversion::buildPayload($click, 'Purchase', 5.0, 'EUR', 'website');
        $this->assertSame('fb.1.1700000001.FROMSTRING', $payload['user_data']['fbc']);
    }

    public function testBuildPayloadOmitsFbcWhenNoFbclid(): void
    {
        $click = [
            'clickid' => 'CLICK3',
            'time' => 1700000002,
            'ip' => '7.7.7.7',
            'ua' => 'UA3',
            'params' => [],
        ];

        $payload = FbOfflineConversion::buildPayload($click, 'Purchase', 3.0, 'USD', 'website');

        // fbc omitted, but ip/ua remain and the payload is still valid.
        $this->assertArrayNotHasKey('fbc', $payload['user_data']);
        $this->assertSame('7.7.7.7', $payload['user_data']['client_ip_address']);
        $this->assertSame('UA3', $payload['user_data']['client_user_agent']);
        $this->assertSame('CLICK3', $payload['event_id']);
        $this->assertSame('Purchase', $payload['event_name']);
    }

    public function testBuildPayloadHashesEmailAndPhoneFromLeaddata(): void
    {
        $click = [
            'clickid' => 'CLICK4',
            'time' => 1700000003,
            'ip' => '1.1.1.1',
            'ua' => 'UA4',
            'params' => [],
            'leaddata' => json_encode(['em' => ' Test@Example.COM ', 'ph' => '+1 (555) 000']),
        ];

        $payload = FbOfflineConversion::buildPayload($click, 'Lead', 1.0, 'USD', 'website');

        $this->assertSame(hash('sha256', 'test@example.com'), $payload['user_data']['em']);
        // phone normalized to digits only before hashing
        $this->assertSame(hash('sha256', '1555000'), $payload['user_data']['ph']);
    }

    // ── s2s_should_fire: OR (default) ─────────────────────────────────────────

    public function testShouldFireOrMatches(): void
    {
        $click = ['status' => 'Purchase', 'events' => []];
        $this->assertTrue(s2s_should_fire(['Purchase', 'install'], 'or', 'Purchase', $click, null));
    }

    public function testShouldFireOrNoMatch(): void
    {
        $click = ['status' => 'Lead', 'events' => []];
        $this->assertFalse(s2s_should_fire(['Purchase'], 'or', 'Lead', $click, null));
    }

    public function testShouldFireDefaultsToOrForBlankOrUnknownLogic(): void
    {
        $click = ['status' => 'Lead', 'events' => []];
        // empty / unknown matchLogic behaves as OR (legacy default)
        $this->assertTrue(s2s_should_fire(['Lead'], '', 'Lead', $click, null));
        $this->assertTrue(s2s_should_fire(['Lead'], 'whatever', 'Lead', $click, null));
    }

    public function testShouldFireOrRequiresCurrentEventListed(): void
    {
        $click = ['status' => 'Trash', 'events' => []];
        // current event not in the list -> never fires, even in OR
        $this->assertFalse(s2s_should_fire(['Lead', 'Purchase'], 'or', 'Trash', $click, null));
    }

    // ── s2s_should_fire: AND ──────────────────────────────────────────────────

    public function testShouldFireAndCurrentEventOnlyCounts(): void
    {
        // single-event AND list: the just-fired event counts as occurred.
        $click = ['status' => 'Purchase', 'events' => []];
        $this->assertTrue(s2s_should_fire(['Purchase'], 'and', 'Purchase', $click, null));
    }

    public function testShouldFireAndAllCustomEventsPresent(): void
    {
        // current = Purchase (status matches); other listed custom event "install"
        // must be present in the click's recorded events map.
        $click = ['status' => 'Purchase', 'events' => ['install' => 1.0]];
        $this->assertTrue(s2s_should_fire(['Purchase', 'install'], 'and', 'Purchase', $click, null));
    }

    public function testShouldFireAndMissingCustomEventBlocks(): void
    {
        // "install" not recorded -> AND fails.
        $click = ['status' => 'Purchase', 'events' => []];
        $this->assertFalse(s2s_should_fire(['Purchase', 'install'], 'and', 'Purchase', $click, null));
    }

    public function testShouldFireAndCustomEventCurrentWithStatusPresent(): void
    {
        // current = "install" (browser event, present); other listed "Purchase"
        // must equal the click's latest status.
        $click = ['status' => 'Purchase', 'events' => ['install' => 1.0]];
        $this->assertTrue(s2s_should_fire(['install', 'Purchase'], 'and', 'install', $click, null));

        $click2 = ['status' => 'Lead', 'events' => ['install' => 1.0]];
        $this->assertFalse(s2s_should_fire(['install', 'Purchase'], 'and', 'install', $click2, null));
    }

    public function testShouldFireAndAcceptsEventsAsJsonString(): void
    {
        // clicks.events may still be a raw JSON string; helper normalizes it.
        $click = ['status' => 'Purchase', 'events' => json_encode(['install' => 2.0])];
        $this->assertTrue(s2s_should_fire(['Purchase', 'install'], 'and', 'Purchase', $click, null));
    }
}
