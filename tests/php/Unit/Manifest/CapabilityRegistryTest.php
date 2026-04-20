<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Manifest;

use MustUse\Pub\Manifest\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    public function test_isBlockClassified_returns_true_for_block_capabilities(): void
    {
        foreach (CapabilityRegistry::BLOCK_CAPABILITIES as $capability) {
            self::assertTrue(
                CapabilityRegistry::isBlockClassified($capability),
                \sprintf('Expected "%s" to be block-classified.', $capability)
            );
        }
    }

    public function test_isBlockClassified_returns_false_for_non_block(): void
    {
        self::assertFalse(CapabilityRegistry::isBlockClassified('secure_storage'));
        self::assertFalse(CapabilityRegistry::isBlockClassified('push_notifications'));
        self::assertFalse(CapabilityRegistry::isBlockClassified('nfc'));
        self::assertFalse(CapabilityRegistry::isBlockClassified('bogus_capability'));
    }

    public function test_classify_returns_classification_or_null(): void
    {
        self::assertSame('block', CapabilityRegistry::classify('camera'));
        self::assertSame('block', CapabilityRegistry::classify('haptics'));
        self::assertSame('block', CapabilityRegistry::classify('geolocation'));
        self::assertSame('shell', CapabilityRegistry::classify('secure_storage'));
        self::assertSame('deferred', CapabilityRegistry::classify('nfc'));
        self::assertNull(CapabilityRegistry::classify('nonsense'));
    }

    public function test_isKnown_returns_true_only_for_registered_capabilities(): void
    {
        self::assertTrue(CapabilityRegistry::isKnown('camera'));
        self::assertTrue(CapabilityRegistry::isKnown('geolocation'));
        self::assertFalse(CapabilityRegistry::isKnown(''));
        self::assertFalse(CapabilityRegistry::isKnown('not_a_capability'));
    }

    public function test_allByClassification_groups_every_capability(): void
    {
        $grouped = CapabilityRegistry::allByClassification();
        $flattened = \array_merge(...\array_values($grouped));
        \sort($flattened);

        $expected = \array_keys(CapabilityRegistry::MOBILE_CAPABILITIES);
        \sort($expected);

        self::assertSame($expected, $flattened);
        self::assertSame(
            ['block', 'shell', 'deferred'],
            \array_keys($grouped),
            'Classification keys should remain insertion-ordered.'
        );
    }
}
