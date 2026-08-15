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
    public function bark_level_is_a_select_with_supported_notification_levels(): void
    {
        $config = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $field = $config['config']['bark_level'];

        self::assertSame('select', $field['type']);
        self::assertSame('timeSensitive', $field['default']);
        self::assertSame(
            ['timeSensitive', 'critical'],
            array_column($field['options'], 'value')
        );
    }
}
