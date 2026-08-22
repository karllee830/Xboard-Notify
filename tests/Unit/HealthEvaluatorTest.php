<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plugin\XboardNotify\Support\HealthEvaluator;

final class HealthEvaluatorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private HealthEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new HealthEvaluator();
    }

    #[Test]
    public function it_alerts_only_after_the_offline_grace_period(): void
    {
        $healthy = $this->machine([
            'last_seen_at' => self::NOW - 299,
        ]);
        $offline = $this->machine([
            'last_seen_at' => self::NOW - 300,
        ]);

        self::assertArrayNotHasKey('offline', $this->issues($healthy));
        $report = $this->evaluator->evaluate($offline, $this->config(), self::NOW);

        self::assertSame(['offline'], array_keys($report['issues']));
        self::assertNotContains('load_stale', array_keys($report['issues']));
    }

    #[Test]
    public function it_does_not_notify_for_stale_load_or_use_old_resource_values(): void
    {
        $machine = $this->machine([
            'load_status' => [
                'updated_at' => self::NOW - 300,
                'cpu' => 100,
                'mem' => ['total' => 100, 'used' => 100],
            ],
        ]);

        $report = $this->evaluator->evaluate($machine, $this->config(), self::NOW);
        $issues = $report['issues'];

        self::assertSame([], $issues);
        self::assertContains('cpu', $report['suppressed_conditions']);
        self::assertContains('load_stale', $report['discarded_conditions']);
    }

    #[Test]
    public function it_detects_each_resource_at_its_threshold(): void
    {
        $machine = $this->machine([
            'load_status' => [
                'updated_at' => self::NOW,
                'cpu' => 90,
                'mem' => ['total' => 1000, 'used' => 900],
                'swap' => ['total' => 1000, 'used' => 900],
                'disk' => ['total' => 1000, 'used' => 900],
            ],
        ]);

        $issues = $this->issues($machine);

        self::assertSame(['cpu', 'memory', 'swap', 'disk'], array_keys($issues));
        self::assertSame(180, $issues['cpu']['sustain_seconds']);
    }

    #[Test]
    public function zero_threshold_disables_a_resource_alert(): void
    {
        $machine = $this->machine([
            'load_status' => [
                'updated_at' => self::NOW,
                'cpu' => 100,
                'mem' => ['total' => 1000, 'used' => 1000],
            ],
        ]);
        $config = $this->config([
            'cpu_threshold_percent' => 0,
            'memory_threshold_percent' => 0,
        ]);

        self::assertSame([], $this->issues($machine, $config));
    }

    #[Test]
    public function a_machine_without_swap_is_skipped(): void
    {
        $machine = $this->machine([
            'load_status' => [
                'updated_at' => self::NOW,
                'cpu' => 10,
                'swap' => ['total' => 0, 'used' => 0],
            ],
        ]);

        self::assertArrayNotHasKey('swap', $this->issues($machine));
    }

    /**
     * @param array<string, mixed> $machine
     * @param array<string, mixed>|null $config
     * @return array<string, array<string, int|string|float>>
     */
    private function issues(array $machine, ?array $config = null): array
    {
        return $this->evaluator->evaluate($machine, $config ?? $this->config(), self::NOW)['issues'];
    }

    /** @param array<string, mixed> $overrides */
    private function machine(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 1,
            'name' => 'edge-01',
            'created_at' => self::NOW - 3600,
            'last_seen_at' => self::NOW,
            'load_status' => [
                'updated_at' => self::NOW,
                'cpu' => 10,
                'mem' => ['total' => 1000, 'used' => 100],
                'swap' => ['total' => 0, 'used' => 0],
                'disk' => ['total' => 1000, 'used' => 100],
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'offline_after_minutes' => 5,
            'load_stale_after_minutes' => 5,
            'resource_sustain_minutes' => 3,
            'cpu_threshold_percent' => 90,
            'memory_threshold_percent' => 90,
            'swap_threshold_percent' => 90,
            'disk_threshold_percent' => 90,
        ], $overrides);
    }
}
