<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Support;

final class HealthEvaluator
{
    /**
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $config
     * @return array{
     *     issues: array<string, array<string, int|string|float>>,
     *     suppressed_conditions: array<int, string>,
     *     discarded_conditions: array<int, string>
     * }
     */
    public function evaluate(array $machine, array $config, int $now): array
    {
        $createdAt = max(0, (int) ($machine['created_at'] ?? 0));
        $lastSeenAt = max(0, (int) ($machine['last_seen_at'] ?? 0));
        $heartbeatBaseline = $lastSeenAt > 0 ? $lastSeenAt : $createdAt;
        $offlineAfter = max(1, (int) ($config['offline_after_minutes'] ?? 5)) * 60;

        if ($heartbeatBaseline > 0 && ($now - $heartbeatBaseline) >= $offlineAfter) {
            $elapsed = max(0, $now - $heartbeatBaseline);

            return [
                'issues' => [
                    'offline' => $this->issue(
                        'offline',
                        '服务器离线',
                        sprintf('心跳已中断 %s，最后上报于 %s', $this->formatDuration($elapsed), $this->formatTime($heartbeatBaseline)),
                        $heartbeatBaseline,
                        0
                    ),
                ],
                'suppressed_conditions' => ['load_stale', 'cpu', 'memory', 'swap', 'disk'],
                'discarded_conditions' => ['load_stale'],
            ];
        }

        $load = is_array($machine['load_status'] ?? null) ? $machine['load_status'] : [];
        $loadUpdatedAt = max(0, (int) ($load['updated_at'] ?? 0));
        $loadBaseline = $loadUpdatedAt > 0 ? $loadUpdatedAt : $createdAt;
        $loadStaleAfter = max(1, (int) ($config['load_stale_after_minutes'] ?? 5)) * 60;

        if ($loadBaseline > 0 && ($now - $loadBaseline) >= $loadStaleAfter) {
            // Never alert from stale resource values. Stale load data is not a
            // notification condition by itself.
            return [
                'issues' => [],
                'suppressed_conditions' => ['cpu', 'memory', 'swap', 'disk'],
                'discarded_conditions' => ['load_stale'],
            ];
        }

        if ($load === []) {
            return [
                'issues' => [],
                'suppressed_conditions' => ['cpu', 'memory', 'swap', 'disk'],
                'discarded_conditions' => ['load_stale'],
            ];
        }

        $sustainSeconds = max(0, (int) ($config['resource_sustain_minutes'] ?? 3)) * 60;
        $issues = [];

        $cpu = max(0.0, (float) ($load['cpu'] ?? 0));
        $cpuThreshold = $this->threshold($config, 'cpu_threshold_percent', 90.0);
        if ($cpuThreshold > 0 && $cpu >= $cpuThreshold) {
            $issues['cpu'] = $this->issue(
                'cpu',
                'CPU 占用过高',
                sprintf('CPU %.1f%%（阈值 %.1f%%）', $cpu, $cpuThreshold),
                $now,
                $sustainSeconds,
                $cpu
            );
        }

        $this->appendUsageIssue($issues, 'memory', '内存占用过高', '内存', $load['mem'] ?? null, $config, 'memory_threshold_percent', 90.0, $now, $sustainSeconds);
        $this->appendUsageIssue($issues, 'swap', 'Swap 占用过高', 'Swap', $load['swap'] ?? null, $config, 'swap_threshold_percent', 90.0, $now, $sustainSeconds);
        $this->appendUsageIssue($issues, 'disk', '磁盘占用过高', '磁盘', $load['disk'] ?? null, $config, 'disk_threshold_percent', 90.0, $now, $sustainSeconds);

        return [
            'issues' => $issues,
            'suppressed_conditions' => [],
            'discarded_conditions' => ['load_stale'],
        ];
    }

    /**
     * @param array<string, array<string, int|string|float>> $issues
     * @param mixed $usage
     * @param array<string, mixed> $config
     */
    private function appendUsageIssue(
        array &$issues,
        string $condition,
        string $label,
        string $resourceName,
        mixed $usage,
        array $config,
        string $thresholdKey,
        float $defaultThreshold,
        int $now,
        int $sustainSeconds
    ): void {
        if (!is_array($usage)) {
            return;
        }

        $total = max(0, (int) ($usage['total'] ?? 0));
        $used = max(0, (int) ($usage['used'] ?? 0));
        $threshold = $this->threshold($config, $thresholdKey, $defaultThreshold);

        if ($total <= 0 || $threshold <= 0) {
            return;
        }

        $percent = min(100.0, ($used / $total) * 100);
        if ($percent < $threshold) {
            return;
        }

        $issues[$condition] = $this->issue(
            $condition,
            $label,
            sprintf(
                '%s %.1f%%（%s / %s，阈值 %.1f%%）',
                $resourceName,
                $percent,
                $this->formatBytes($used),
                $this->formatBytes($total),
                $threshold
            ),
            $now,
            $sustainSeconds,
            $percent
        );
    }

    /**
     * @return array<string, int|string|float>
     */
    private function issue(
        string $condition,
        string $label,
        string $summary,
        int $firstDetectedAt,
        int $sustainSeconds,
        float $value = 0.0
    ): array {
        return [
            'condition' => $condition,
            'label' => $label,
            'summary' => $summary,
            'first_detected_at' => $firstDetectedAt,
            'sustain_seconds' => $sustainSeconds,
            'value' => $value,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function threshold(array $config, string $key, float $default): float
    {
        return min(100.0, max(0.0, (float) ($config[$key] ?? $default)));
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, (int) floor($seconds / 60)) . ' 分钟';
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        return $minutes > 0 ? sprintf('%d 小时 %d 分钟', $hours, $minutes) : sprintf('%d 小时', $hours);
    }

    private function formatTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf($power === 0 ? '%.0f %s' : '%.1f %s', $value, $units[$power]);
    }
}
