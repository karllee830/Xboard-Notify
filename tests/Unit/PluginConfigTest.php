<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Tests\Unit;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PluginConfigTest extends TestCase
{
    /** @throws JsonException */
    #[Test]
    public function bark_notification_levels_are_separate_selects_with_silent_level(): void
    {
        $config = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $offlineField = $config['config']['bark_offline_level'];
        $loadField = $config['config']['bark_load_level'];
        $recoveryField = $config['config']['bark_recovery_level'];

        foreach ([$offlineField, $loadField, $recoveryField] as $field) {
            self::assertSame('select', $field['type']);
            self::assertSame(
                ['passive', 'active', 'timeSensitive', 'critical'],
                array_column($field['options'], 'value')
            );
        }

        self::assertSame('passive', $offlineField['default']);
        self::assertSame('timeSensitive', $loadField['default']);
        self::assertSame('passive', $recoveryField['default']);
        self::assertArrayNotHasKey('bark_level', $config['config']);
        self::assertArrayNotHasKey('notify_load_stale', $config['config']);
    }
}
