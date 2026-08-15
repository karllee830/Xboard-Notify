<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Plugin\XboardNotify\Plugin;
use RuntimeException;
use Throwable;

final class TestBarkCommand extends Command
{
    protected $signature = 'xboard-notify:test';

    protected $description = '发送一条 Bark 测试通知';

    public function handle(): int
    {
        try {
            $this->plugin()->sendTestNotification();
            $this->info('Bark 测试通知发送成功。');
            return self::SUCCESS;
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
