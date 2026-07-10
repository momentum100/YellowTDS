<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../fb_capi.php';

/**
 * Covers the pure request-normalization helper behind api/match.php
 * (match_normalize_params in fb_capi.php). Network-free and DB-free: the helper
 * only validates/normalizes the incoming ip+ua+event+value+window params and is
 * the one piece of the endpoint that can be unit-tested in isolation.
 *
 * The DB match (find_click_by_ip_ua) and the FB fire (fire_fb_offline_for_click)
 * are NOT unit-tested here: both need a live SQLite DB / network and the test
 * env (old PEAR PHPUnit + no sqlite3 CLI) cannot exercise them without faking a
 * DB, which we deliberately do not do.
 */
class MatchEndpointTest extends TestCase
{
    private function server(array $overrides = []): array
    {
        return $overrides + [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'ServerFallbackUA/1.0',
        ];
    }

    public function testValidRequestNormalizes(): void
    {
        $out = match_normalize_params([
            'apikey' => 'KEY-123',
            'ip' => '8.8.8.8',
            'ua' => 'Mozilla/5.0 Real',
            'event' => 'install',
            'value' => '2.5',
            'window' => '3600',
        ], $this->server());

        $this->assertTrue($out['ok']);
        $this->assertNull($out['error']);
        $this->assertSame('KEY-123', $out['apikey']);
        $this->assertSame('8.8.8.8', $out['ip']);
        $this->assertSame('Mozilla/5.0 Real', $out['ua']);
        $this->assertSame('install', $out['event']);
        $this->assertSame(2.5, $out['value']);
        $this->assertSame(3600, $out['window']);
    }

    public function testMissingApikeyRejected(): void
    {
        $out = match_normalize_params([
            'event' => 'install',
            'ip' => '8.8.8.8',
            'ua' => 'UA',
        ], $this->server());
        $this->assertFalse($out['ok']);
        $this->assertNotNull($out['error']);
    }

    public function testMissingEventRejected(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K',
            'ip' => '8.8.8.8',
            'ua' => 'UA',
        ], $this->server());
        $this->assertFalse($out['ok']);
    }

    public function testInvalidEventCharsRejected(): void
    {
        // uppercase / punctuation not allowed by ^[a-z0-9_]+$
        foreach (['Install', 'in stall', 'in-stall', 'inst@ll', ''] as $bad) {
            $out = match_normalize_params([
                'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => $bad,
            ], $this->server());
            $this->assertFalse($out['ok'], "event '$bad' should be rejected");
        }
    }

    public function testValueDefaultsToOne(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => 'install',
        ], $this->server());
        $this->assertTrue($out['ok']);
        $this->assertSame(1.0, $out['value']);
    }

    public function testNonPositiveOrNonNumericValueRejected(): void
    {
        foreach (['0', '-3', 'abc'] as $bad) {
            $out = match_normalize_params([
                'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => 'install', 'value' => $bad,
            ], $this->server());
            $this->assertFalse($out['ok'], "value '$bad' should be rejected");
        }
    }

    public function testWindowDefaultsTo72h(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => 'install',
        ], $this->server());
        $this->assertSame(259200, $out['window']);
    }

    public function testWindowClampedToRange(): void
    {
        // below min -> MIN (300)
        $low = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => 'install', 'window' => '10',
        ], $this->server());
        $this->assertSame(300, $low['window']);

        // above max -> MAX (1209600)
        $high = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => 'install', 'window' => '99999999',
        ], $this->server());
        $this->assertSame(1209600, $high['window']);

        // non-numeric window -> default
        $bad = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'ua' => 'UA', 'event' => 'install', 'window' => 'soon',
        ], $this->server());
        $this->assertSame(259200, $bad['window']);
    }

    public function testIpFallsBackToRemoteAddr(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K', 'ua' => 'UA', 'event' => 'install',
        ], $this->server(['REMOTE_ADDR' => '198.51.100.7']));
        $this->assertTrue($out['ok']);
        $this->assertSame('198.51.100.7', $out['ip']);
    }

    public function testUaFallsBackToServerHeader(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'event' => 'install',
        ], $this->server(['HTTP_USER_AGENT' => 'HeaderUA/9']));
        $this->assertTrue($out['ok']);
        $this->assertSame('HeaderUA/9', $out['ua']);
    }

    public function testMissingIpEvenAfterFallbackRejected(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K', 'ua' => 'UA', 'event' => 'install',
        ], ['HTTP_USER_AGENT' => 'UA']); // no REMOTE_ADDR
        $this->assertFalse($out['ok']);
    }

    public function testMissingUaEvenAfterFallbackRejected(): void
    {
        $out = match_normalize_params([
            'apikey' => 'K', 'ip' => '1.2.3.4', 'event' => 'install',
        ], ['REMOTE_ADDR' => '1.2.3.4']); // no HTTP_USER_AGENT
        $this->assertFalse($out['ok']);
    }
}
