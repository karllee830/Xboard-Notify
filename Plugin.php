<?php

declare(strict_types=1);

namespace Plugin\XboardNotify;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\XboardNotify\Services\BarkClient;
use Plugin\XboardNotify\Services\MonitorService;

final class Plugin extends AbstractPlugin
{
    public const CODE = 'xboard_notify';

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

    public function sendTestNotification(): void
    {
        $siteName = trim((string) $this->getConfig('site_name', ''));
        if ($siteName === '' && function_exists('admin_setting')) {
            $siteName = (string) admin_setting('app_name', 'Xboard');
        }

        (new BarkClient($this->getConfig()))->send(
            sprintf('【%s】Bark 测试成功', $siteName !== '' ? $siteName : 'Xboard'),
            "Xboard Notify 已正确连接 Bark。\n发送时间：" . now()->format('Y-m-d H:i:s')
        );
    }
}
