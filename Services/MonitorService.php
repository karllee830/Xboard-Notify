<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Services;

use App\Models\ServerMachine;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Plugin\XboardNotify\Models\AlertState;
use Plugin\XboardNotify\Support\HealthEvaluator;
use Throwable;

final class MonitorService
{
    private readonly BarkClient $bark;

    private readonly HealthEvaluator $evaluator;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->bark = new BarkClient($config);
        $this->evaluator = new HealthEvaluator();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(bool $force = false): array
    {
        $result = [
            'configured' => $this->bark->isConfigured(),
            'machines_scanned' => 0,
            'alerts_sent' => 0,
            'recoveries_sent' => 0,
            'active_conditions' => 0,
            'errors' => [],
        ];

        if (!$result['configured']) {
            $result['errors'][] = '尚未配置 Bark Device Key';
            return $result;
        }

        $machines = $this->machines();
        $this->discardUnmonitoredStates($machines);
        $result['machines_scanned'] = $machines->count();
        $now = time();

        foreach ($machines as $machine) {
            try {
                $machineResult = $this->inspectMachine($machine, $now, $force);
                $result['alerts_sent'] += $machineResult['alerts_sent'];
                $result['recoveries_sent'] += $machineResult['recoveries_sent'];
                $result['active_conditions'] += $machineResult['active_conditions'];
                array_push($result['errors'], ...$machineResult['errors']);
            } catch (Throwable $exception) {
                $message = sprintf('服务器 #%d %s：%s', $machine->id, $machine->name, $exception->getMessage());
                $result['errors'][] = $message;
                Log::error('Xboard Notify monitor failed', [
                    'machine_id' => $machine->id,
                    'exception' => $exception,
                ]);
            }
        }

        return $result;
    }

    /** @return Collection<int, ServerMachine> */
    private function machines(): Collection
    {
        $ignoredIds = collect($this->config['ignored_machine_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return ServerMachine::query()
            ->when(!$this->toBool($this->config['monitor_disabled_servers'] ?? false), static function ($query): void {
                $query->where('is_active', true);
            })
            ->when($ignoredIds !== [], static function ($query) use ($ignoredIds): void {
                $query->whereNotIn('id', $ignoredIds);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{alerts_sent: int, recoveries_sent: int, active_conditions: int, errors: array<int, string>}
     */
    private function inspectMachine(ServerMachine $machine, int $now, bool $force): array
    {
        $health = $this->evaluator->evaluate([
            'id' => (int) $machine->id,
            'name' => (string) $machine->name,
            'created_at' => $this->timestamp($machine->created_at),
            'last_seen_at' => (int) ($machine->last_seen_at ?? 0),
            'load_status' => $machine->load_status,
        ], $this->config, $now);
        $issues = $health['issues'];
        $suppressedConditions = array_fill_keys($health['suppressed_conditions'], true);

        if ($health['discarded_conditions'] !== []) {
            AlertState::query()
                ->where('machine_id', $machine->id)
                ->whereIn('condition', $health['discarded_conditions'])
                ->delete();
        }

        /** @var Collection<int, AlertState> $existingStates */
        $existingStates = AlertState::query()
            ->where('machine_id', $machine->id)
            ->get()
            ->keyBy('condition');

        $alertStates = collect();

        foreach ($issues as $condition => $issue) {
            /** @var AlertState|null $state */
            $state = $existingStates->get($condition);

            if (!$state) {
                $state = new AlertState([
                    'machine_id' => $machine->id,
                    'condition' => $condition,
                    'first_detected_at' => (int) $issue['first_detected_at'],
                ]);
            }

            $state->last_summary = (string) $issue['summary'];
            $state->save();

            $mature = $force || ($now - (int) $state->first_detected_at) >= (int) $issue['sustain_seconds'];
            if ($mature && $this->notificationIsDue($state, $now, $force)) {
                $alertStates->push($state);
            }
        }

        /** @var Collection<int, AlertState> $resolvedStates */
        $resolvedStates = $existingStates->reject(
            static fn (AlertState $state): bool => isset($issues[$state->condition]) || isset($suppressedConditions[$state->condition])
        );
        $recoveryStates = $resolvedStates->filter(static fn (AlertState $state): bool => $state->last_notified_at !== null);
        $resolvedStates->filter(static fn (AlertState $state): bool => $state->last_notified_at === null)
            ->each(static fn (AlertState $state): bool => (bool) $state->delete());

        $result = [
            'alerts_sent' => 0,
            'recoveries_sent' => 0,
            'active_conditions' => count($issues),
            'errors' => [],
        ];

        foreach ($this->groupStatesByNotificationType($alertStates) as $notificationType => $states) {
            try {
                $this->sendAlert(
                    $machine,
                    $states,
                    $now,
                    $this->notificationLevel((string) $notificationType)
                );
                AlertState::query()
                    ->whereIn('id', $states->pluck('id'))
                    ->update(['last_notified_at' => $now, 'updated_at' => now()]);
                $result['alerts_sent']++;
            } catch (Throwable $exception) {
                $result['errors'][] = sprintf('服务器 #%d 告警推送失败：%s', $machine->id, $exception->getMessage());
                Log::warning('Xboard Notify Bark alert failed', [
                    'machine_id' => $machine->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($recoveryStates->isNotEmpty()) {
            if (!$this->toBool($this->config['recovery_notify'] ?? true)) {
                AlertState::query()->whereIn('id', $recoveryStates->pluck('id'))->delete();
            } else {
                foreach ($this->groupStatesByNotificationType($recoveryStates) as $states) {
                    try {
                        $this->sendRecovery(
                            $machine,
                            $states,
                            $now,
                            $this->recoveryNotificationLevel()
                        );
                        AlertState::query()->whereIn('id', $states->pluck('id'))->delete();
                        $result['recoveries_sent']++;
                    } catch (Throwable $exception) {
                        $result['errors'][] = sprintf('服务器 #%d 恢复通知推送失败：%s', $machine->id, $exception->getMessage());
                        Log::warning('Xboard Notify Bark recovery failed', [
                            'machine_id' => $machine->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $result;
    }

    private function notificationIsDue(AlertState $state, int $now, bool $force): bool
    {
        if ($force || $state->last_notified_at === null) {
            return true;
        }

        $repeatMinutes = max(0, (int) ($this->config['repeat_interval_minutes'] ?? 60));

        return $repeatMinutes > 0 && ($now - (int) $state->last_notified_at) >= ($repeatMinutes * 60);
    }

    /** @param Collection<int, AlertState> $states */
    private function sendAlert(ServerMachine $machine, Collection $states, int $now, string $level): void
    {
        $lines = $states
            ->map(static fn (AlertState $state): string => '• ' . $state->last_summary)
            ->implode("\n");

        $this->bark->send(
            sprintf('【%s】服务器告警：%s', $this->siteName(), $machine->name),
            sprintf("服务器：%s (#%d)\n时间：%s\n异常：\n%s", $machine->name, $machine->id, date('Y-m-d H:i:s', $now), $lines),
            $level
        );
    }

    /** @param Collection<int, AlertState> $states */
    private function sendRecovery(ServerMachine $machine, Collection $states, int $now, string $level): void
    {
        $lines = $states
            ->map(function (AlertState $state) use ($now): string {
                $duration = $this->formatDuration(max(0, $now - (int) $state->first_detected_at));
                return sprintf('• %s（持续 %s）', $this->conditionLabel($state->condition), $duration);
            })
            ->implode("\n");

        $this->bark->send(
            sprintf('【%s】服务器恢复：%s', $this->siteName(), $machine->name),
            sprintf("服务器：%s (#%d)\n时间：%s\n已恢复：\n%s", $machine->name, $machine->id, date('Y-m-d H:i:s', $now), $lines),
            $level
        );
    }

    /** @param Collection<int, AlertState> $states */
    private function groupStatesByNotificationType(Collection $states): Collection
    {
        return $states->groupBy(
            fn (AlertState $state): string => $this->notificationType((string) $state->condition)
        );
    }

    private function notificationType(string $condition): string
    {
        return $condition === 'offline' ? 'offline' : 'load';
    }

    private function notificationLevel(string $notificationType): string
    {
        $key = $notificationType === 'offline' ? 'bark_offline_level' : 'bark_load_level';
        $level = trim((string) ($this->config[$key] ?? $this->config['bark_level'] ?? 'timeSensitive'));

        return $level !== '' ? $level : 'timeSensitive';
    }

    private function recoveryNotificationLevel(): string
    {
        $level = trim((string) ($this->config['bark_recovery_level'] ?? 'active'));

        return $level !== '' ? $level : 'active';
    }

    /** @param Collection<int, ServerMachine> $machines */
    private function discardUnmonitoredStates(Collection $machines): void
    {
        $ids = $machines->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($ids === []) {
            AlertState::query()->delete();
            return;
        }

        AlertState::query()->whereNotIn('machine_id', $ids)->delete();
    }

    private function siteName(): string
    {
        $siteName = trim((string) ($this->config['site_name'] ?? ''));
        if ($siteName === '' && function_exists('admin_setting')) {
            $siteName = trim((string) admin_setting('app_name', 'Xboard'));
        }

        return $siteName !== '' ? $siteName : 'Xboard';
    }

    private function conditionLabel(string $condition): string
    {
        return match ($condition) {
            'offline' => '服务器离线',
            'load_stale' => '负载数据过期',
            'cpu' => 'CPU 占用过高',
            'memory' => '内存占用过高',
            'swap' => 'Swap 占用过高',
            'disk' => '磁盘占用过高',
            default => $condition,
        };
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

    private function timestamp(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = is_string($value) ? strtotime($value) : false;

        return $timestamp === false ? 0 : $timestamp;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
