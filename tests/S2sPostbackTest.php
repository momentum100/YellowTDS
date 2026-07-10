<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../campaign.php';

/**
 * Covers the backward-compatible S2sPostback model additions: the matchLogic
 * field and the statuses ∪ customEvents union performed in fromArray().
 * Network-free (no DB access is triggered by these paths).
 */
class S2sPostbackTest extends TestCase
{
    public function testFromArrayUnionsStatusesAndCustomEvents(): void
    {
        $s2s = S2sPostback::fromArray([
            'url' => '',
            'method' => 'POST',
            'events' => ['Purchase', 'Lead'],
            'type' => 'fb_offline',
            'customEvents' => 'install, subscribe',
            'matchLogic' => 'and',
        ]);

        // union = checked statuses ∪ parsed custom events (trimmed)
        $this->assertContains('Purchase', $s2s->events);
        $this->assertContains('Lead', $s2s->events);
        $this->assertContains('install', $s2s->events);
        $this->assertContains('subscribe', $s2s->events);
        $this->assertCount(4, $s2s->events);
        $this->assertSame('and', $s2s->matchLogic);
        $this->assertSame('fb_offline', $s2s->type);
    }

    public function testFromArrayDedupesAndDropsEmptyCustomEvents(): void
    {
        $s2s = S2sPostback::fromArray([
            'events' => ['Purchase'],
            // duplicate of a checked status + a dupe + empties/whitespace
            'customEvents' => 'Purchase, install, install, ,   ',
        ]);

        // Purchase kept once, install once, empties dropped
        $this->assertSame(['Purchase', 'install'], $s2s->events);
    }

    public function testFromArrayBackwardCompatibleDefaults(): void
    {
        // legacy entry: no type/matchLogic/customEvents -> defaults, events unchanged
        $s2s = S2sPostback::fromArray([
            'url' => 'https://x/postback',
            'method' => 'GET',
            'events' => ['Lead'],
        ]);

        $this->assertSame('url', $s2s->type);
        $this->assertSame('or', $s2s->matchLogic);
        $this->assertSame(['Lead'], $s2s->events);
    }

    public function testJsonSerializeRoundTripsMatchLogic(): void
    {
        $s2s = S2sPostback::fromArray(['events' => ['Lead'], 'matchLogic' => 'and']);
        $json = $s2s->jsonSerialize();
        $this->assertSame('and', $json['matchLogic']);
        $this->assertArrayHasKey('type', $json);
    }
}
