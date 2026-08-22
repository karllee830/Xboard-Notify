<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plugin\XboardNotify\Support\BarkConfigChangeDetector;

final class BarkConfigChangeDetectorTest extends TestCase
{
    private BarkConfigChangeDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new BarkConfigChangeDetector();
    }

    #[Test]
    public function it_detects_changes_to_bark_settings(): void
    {
        $before = ['bark_device_key' => 'old-key'];
        $after = ['bark_device_key' => 'new-key'];

        self::assertTrue($this->detector->hasChanged($before, $after));
    }

    #[Test]
    public function it_detects_changes_to_either_notification_level(): void
    {
        $before = [
            'bark_offline_level' => 'timeSensitive',
            'bark_load_level' => 'timeSensitive',
        ];
        $after = [
            'bark_offline_level' => 'critical',
            'bark_load_level' => 'active',
        ];

        self::assertTrue($this->detector->hasChanged($before, $after));
    }

    #[Test]
    public function it_ignores_unrelated_monitoring_changes(): void
    {
        $before = ['bark_device_key' => 'key', 'cpu_threshold_percent' => 90];
        $after = ['bark_device_key' => 'key', 'cpu_threshold_percent' => 80];

        self::assertFalse($this->detector->hasChanged($before, $after));
    }

    #[Test]
    public function it_compares_boolean_and_number_values_semantically(): void
    {
        $before = ['archive_notifications' => true, 'request_timeout_seconds' => 10];
        $after = ['archive_notifications' => 'true', 'request_timeout_seconds' => '10'];

        self::assertFalse($this->detector->hasChanged($before, $after));
    }

    #[Test]
    public function automatic_testing_is_enabled_by_default_and_can_be_disabled(): void
    {
        self::assertTrue($this->detector->autoTestEnabled([]));
        self::assertFalse($this->detector->autoTestEnabled(['test_on_bark_config_update' => false]));
    }
}
