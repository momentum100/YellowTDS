<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../campaignroute.php';

class CampaignRouteTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testSlugValidationAcceptsFriendlyCampaignPaths(): void
    {
        $this->assertTrue(is_valid_campaign_public_id('my-super-cool-offer-123'));
        $this->assertTrue(is_valid_campaign_public_id('c-a1b2c3d4e5f6'));
        $this->assertFalse(is_valid_campaign_public_id('UPPERCASE'));
        $this->assertFalse(is_valid_campaign_public_id('bad_slug'));
        $this->assertFalse(is_valid_campaign_public_id('api'));
    }

    public function testLegacyCampaignGetsStableFallbackSlug(): void
    {
        $settings = ['apikey' => 'legacy-key'];
        $first = get_campaign_public_id(7, $settings);
        $second = get_campaign_public_id(7, $settings);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^c-[a-f0-9]{12}$/', $first);
    }

    public function testRequestRouteSeparatesSlugAndLandingPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/my-super-cool-offer-123/pages/reservations.html?utm_source=test';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->assertSame([
            'public_id' => 'my-super-cool-offer-123',
            'relative_path' => 'pages/reservations.html',
        ], get_campaign_request_route());
    }

    public function testChangedSlugKeepsEveryPreviousSlugAsAlias(): void
    {
        $settings = [
            'apikey' => 'key',
            'publicid' => 'first-offer',
            'publicidaliases' => ['older-offer'],
        ];

        $aliases = build_campaign_public_id_aliases(1, $settings, 'new-offer');
        $this->assertSame(['older-offer', 'first-offer'], $aliases);
        $this->assertTrue(campaign_accepts_public_id(1, $settings, 'older-offer'));
    }

    public function testReusingAnAliasMakesItCurrentWithoutDuplicatingHistory(): void
    {
        $settings = [
            'apikey' => 'key',
            'publicid' => 'current-offer',
            'publicidaliases' => ['old-offer'],
        ];

        $this->assertSame(
            ['current-offer'],
            build_campaign_public_id_aliases(1, $settings, 'old-offer')
        );
    }

    public function testMissingAliasHistoryBackfillsTheLegacyGeneratedSlug(): void
    {
        $settings = [
            'apikey' => 'legacy-key',
            'publicid' => 'friendly-offer',
        ];

        $legacyPublicId = get_legacy_campaign_public_id(7, $settings);
        $this->assertSame(
            [$legacyPublicId],
            get_campaign_public_id_aliases($settings, 7)
        );
        $this->assertTrue(campaign_accepts_public_id(7, $settings, $legacyPublicId));
    }
}
