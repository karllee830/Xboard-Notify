<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Plugin\XboardNotify\Plugin;
use RuntimeException;
use Throwable;

final class CheckCommand extends Command
{
    protected $signature = 'xboard-notify:check
        {--force : 忽略资源持续时间和重复提醒间隔，立即发送当前异常}';

    protected $description = '立即检查 Xboard 服务器状态并按需发送 Bark 通知';

    public function handle(): int
    {
        try {
            $result = $this->plugin()->runCheck((bool) $this->option('force'));

            $this->table(['项目', '数量'], [
                ['扫描服务器', $result['machines_scanned']],
                ['告警通知', $result['alerts_sent']],
                ['恢复通知', $result['recoveries_sent']],
                ['当前异常状态', $result['active_conditions']],
            ]);

            foreach ($result['errors'] as $error) {
                $this->error((string) $error);
            }

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function plugin(): Plugin
    {
        $plugin = app(PluginManager::class)->getEnabledPlugins()[Plugin::CODE] ?? null;
        if (!$plugin instanceof Plugin) {
            throw new RuntimeException('Xboard Notify 插件尚未启用');
        }

        return $plugin;
    }
}
