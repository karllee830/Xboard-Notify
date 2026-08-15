<?php

declare(strict_types=1);

namespace Plugin\XboardNotify;

use App\Http\Controllers\V2\Admin\PluginController as AdminPluginController;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Plugin\XboardNotify\Services\BarkClient;
use Plugin\XboardNotify\Services\MonitorService;
use Plugin\XboardNotify\Support\BarkConfigChangeDetector;
use Throwable;

final class Plugin extends AbstractPlugin
{
    public const CODE = 'xboard_notify';

    public function boot(): void
    {
        if (app()->runningInConsole()
            || !request()->isMethod('POST')
            || request()->input('code') !== self::CODE) {
            return;
        }

        $before = $this->getConfig();

        Event::listen(RequestHandled::class, function (RequestHandled $event) use ($before): void {
            $this->handleConfigUpdated($event, $before);
        });
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule
            ->call(fn (): array => $this->runCheck())
            ->name('xboard-notify:monitor')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(5);
    }

    /**
     * @return array<string, mixed>
     */
    public function runCheck(bool $force = false): array
    {
        return (new MonitorService($this->getConfig()))->run($force);
    }

    /** @param array<string, mixed>|null $config */
    public function sendTestNotification(?array $config = null): void
    {
        $config ??= $this->getConfig();
        $siteName = trim((string) ($config['site_name'] ?? ''));
        if ($siteName === '' && function_exists('admin_setting')) {
            $siteName = (string) admin_setting('app_name', 'Xboard');
        }

        (new BarkClient($config))->send(
            sprintf('【%s】Bark 测试成功', $siteName !== '' ? $siteName : 'Xboard'),
            "Xboard Notify 已正确连接 Bark，配置已保存。\n发送时间：" . now()->format('Y-m-d H:i:s')
        );
    }

    /** @param array<string, mixed> $before */
    private function handleConfigUpdated(RequestHandled $event, array $before): void
    {
        try {
            $route = $event->request->route();
            $expectedAction = AdminPluginController::class . '@updateConfig';

            if (!is_object($route)
                || $route->getActionName() !== $expectedAction
                || !$event->response->isSuccessful()) {
                return;
            }

            $after = app(PluginConfigService::class)->getDbConfig(self::CODE);
            $detector = new BarkConfigChangeDetector();

            if (!$detector->hasChanged($before, $after) || !$detector->autoTestEnabled($after)) {
                return;
            }

            if (trim((string) ($after['bark_device_key'] ?? '')) === '') {
                $this->setBarkTestResult(
                    $event->response,
                    false,
                    '配置更新成功；Bark Device Key 为空，未发送测试通知'
                );
                return;
            }

            try {
                $this->sendTestNotification($after);
                $this->setBarkTestResult($event->response, true, '配置更新成功，Bark 测试通知发送成功');
            } catch (Throwable $exception) {
                Log::warning('Xboard Notify Bark config test failed', [
                    'message' => $exception->getMessage(),
                ]);
                $this->setBarkTestResult(
                    $event->response,
                    false,
                    '配置更新成功，但 Bark 测试通知发送失败：' . $exception->getMessage()
                );
            }
        } catch (Throwable $exception) {
            Log::error('Xboard Notify config update handler failed', [
                'exception' => $exception,
            ]);
        }
    }

    private function setBarkTestResult(mixed $response, bool $sent, string $message): void
    {
        if (!$response instanceof JsonResponse) {
            return;
        }

        $data = $response->getData(true);
        $data['message'] = $message;
        $data['bark_test'] = [
            'sent' => $sent,
            'message' => $message,
        ];
        $response->setData($data);
    }
}
