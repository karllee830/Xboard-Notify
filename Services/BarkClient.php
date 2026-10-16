<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BarkClient
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['bark_device_key'] ?? '')) !== '';
    }

    public function send(string $title, string $body, ?string $level = null): void
    {
        $deviceKey = trim((string) ($this->config['bark_device_key'] ?? ''));
        if ($deviceKey === '') {
            throw new RuntimeException('尚未配置 Bark Device Key');
        }

        $serverUrl = rtrim(trim((string) ($this->config['bark_server_url'] ?? 'https://api.day.app')), '/');
        if (!filter_var($serverUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($serverUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Bark 服务器地址无效');
        }

        $payload = [
            'device_key' => $deviceKey,
            'title' => $title,
            'body' => $body,
            'group' => trim((string) ($this->config['bark_group'] ?? 'Xboard Monitor')),
            'level' => $this->level($level),
        ];

        $sound = trim((string) ($this->config['bark_sound'] ?? ''));
        if ($sound !== '') {
            $payload['sound'] = $sound;
        }

        $url = trim((string) ($this->config['notification_url'] ?? ''));
        if ($url !== '') {
            $payload['url'] = $url;
        }

        if ($this->toBool($this->config['archive_notifications'] ?? true)) {
            $payload['isArchive'] = '1';
        }

        $timeout = min(30, max(2, (int) ($this->config['request_timeout_seconds'] ?? 10)));
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->retry(2, 500, throw: false)
            ->post($serverUrl . '/push', $payload);

        $code = (int) $response->json('code', 0);
        if (!$response->successful() || $code !== 200) {
            $message = (string) $response->json('message', '未知错误');
            throw new RuntimeException(sprintf('Bark 推送失败（HTTP %d，code %d）：%s', $response->status(), $code, $message));
        }
    }

    private function level(?string $level): string
    {
        $level = trim((string) ($level ?? $this->config['bark_offline_level'] ?? $this->config['bark_level'] ?? 'passive'));

        return in_array($level, ['active', 'timeSensitive', 'passive', 'critical'], true)
            ? $level
            : 'passive';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
