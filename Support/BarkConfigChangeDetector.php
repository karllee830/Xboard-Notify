<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Support;

final class BarkConfigChangeDetector
{
    private const BARK_KEYS = [
        'bark_server_url',
        'bark_device_key',
        'bark_group',
        'bark_test_level',
        'bark_offline_level',
        'bark_load_level',
        'bark_recovery_level',
        'bark_sound',
        'notification_url',
        'archive_notifications',
        'request_timeout_seconds',
    ];

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function hasChanged(array $before, array $after): bool
    {
        foreach (self::BARK_KEYS as $key) {
            if ($this->normalize($key, $before[$key] ?? null) !== $this->normalize($key, $after[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $config */
    public function autoTestEnabled(array $config): bool
    {
        return $this->toBool($config['test_on_bark_config_update'] ?? true);
    }

    private function normalize(string $key, mixed $value): bool|int|string
    {
        return match ($key) {
            'archive_notifications' => $this->toBool($value),
            'request_timeout_seconds' => (int) $value,
            default => trim((string) $value),
        };
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
