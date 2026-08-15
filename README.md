# Xboard Notify

[![CI](https://github.com/karllee830/Xboard-Notify/actions/workflows/ci.yml/badge.svg)](https://github.com/karllee830/Xboard-Notify/actions/workflows/ci.yml)
[![Package](https://github.com/karllee830/Xboard-Notify/actions/workflows/package.yml/badge.svg)](https://github.com/karllee830/Xboard-Notify/actions/workflows/package.yml)

为 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 编写的 Bark 服务器监控插件。插件读取“节点管理 → 服务器管理”中的心跳和负载数据，在服务器持续离线、负载数据停止更新或资源占用异常时推送通知，并在恢复后通知。

## 功能

- 每分钟检查所有已启用服务器，不需要修改 Xboard 核心代码。
- 离线持续时间可配置，避免网络瞬断造成误报。
- 监控 CPU、内存、Swap、磁盘，阈值和异常持续时间均可配置。
- 监控负载数据是否停止更新，且不会使用过期数据制造资源告警。
- 支持 Bark 官方服务和自建 bark-server。
- 支持持续异常重复提醒、恢复通知、服务器忽略列表。
- 告警状态持久化到独立数据表，Xboard 重启或清理 Redis 后不会重复首报。
- 内置手动检查和 Bark 连通性测试命令。

## 兼容性

- PHP 8.2 或更高版本。
- 使用 Xboard 的 `ServerMachine`、插件调度器和插件迁移机制。
- 开发时对照的 Xboard `master` 提交：`60ba57142b7b04c74dbd943b551e32df98df0101`（2026-08-07）。

如果使用较旧的 Xboard，须先确认数据库中已有 `v2_server_machine` 表，且 `App\Models\ServerMachine` 可用。

## 安装

### 使用插件包

1. 从 Releases 下载 `xboard-notify-vX.Y.Z.zip`。
2. 进入 Xboard 管理后台的插件管理页面并上传压缩包。
3. 安装插件；安装时会自动创建 `xboard_notify_alert_states` 表。
4. 填写 Bark 配置并启用插件。
5. 重启 Xboard/Octane 工作进程，使插件命令和配置完整生效。

### 从源码安装

将仓库放到 Xboard 的 `plugins/XboardNotify` 目录：

```bash
cd /path/to/Xboard/plugins
git clone https://github.com/karllee830/Xboard-Notify.git XboardNotify
```

然后在 Xboard 插件管理页面完成安装、配置和启用。目录名必须为 `XboardNotify`，插件代码为 `xboard_notify`。

## Bark 配置

从 Bark App 复制推送地址，例如：

```text
https://api.day.app/AbCdEf123456
```

拆分填写：

- Bark 服务器地址：`https://api.day.app`
- Bark Device Key：`AbCdEf123456`

自建服务同理，只需将服务器地址改为自建 bark-server 的根地址。插件使用 Bark V2 的 `POST /push` JSON API，Device Key 不会写入日志。

建议先执行测试：

```bash
php artisan xboard-notify:test
```

Docker 部署时在 Xboard 容器内执行，例如：

```bash
docker compose exec xboard php artisan xboard-notify:test
```

## 告警规则

| 规则 | 默认值 | 行为 |
| --- | ---: | --- |
| 离线时间 | 5 分钟 | 最后心跳超过该时间后告警 |
| 负载数据过期 | 5 分钟 | 服务器仍有心跳但负载停止更新时告警 |
| 资源异常持续时间 | 3 分钟 | CPU/内存/Swap/磁盘连续超阈值后告警 |
| 各资源阈值 | 90% | 设为 `0` 可单独关闭该资源告警 |
| 重复提醒间隔 | 60 分钟 | 持续异常按间隔提醒；设为 `0` 只提醒一次 |
| 恢复通知 | 开启 | 已发送过告警的状态恢复后通知一次 |

服务器离线时只发送离线告警；负载数据过期时只发送数据过期告警，不会继续根据旧数据判断 CPU、内存或磁盘状态。

## 手动检查

按正常持续时间和冷却规则检查：

```bash
php artisan xboard-notify:check
```

立即发送当前异常并忽略资源持续时间、重复提醒间隔：

```bash
php artisan xboard-notify:check --force
```

插件依赖 Xboard 的 Laravel Scheduler 每分钟运行。如果部署环境没有启动 Xboard 的计划任务，自动监控不会执行；可以先用手动检查命令排查。

## 开发与打包

```bash
composer install
composer test
make package
```

`make package` 使用当前 Git `HEAD` 生成 `dist/xboard-notify-vX.Y.Z.zip`，因此打包前应先提交需要发布的修改。开发文件和测试不会进入插件包。

### GitHub Actions 自动打包

- 推送到 `main`：自动运行测试，并生成保留 30 天的 ZIP Artifact。
- 手动运行 `Package` 工作流：按当前 `config.json` 版本生成 ZIP Artifact。
- 推送 `v*` 标签：校验标签与 `config.json` 版本一致，自动创建 GitHub Release，并上传 ZIP 和 SHA-256 文件。

发布新版本时，先更新 `config.json` 和 `CHANGELOG.md`，提交后创建对应标签：

```bash
git tag v0.2.0
git push origin main v0.2.0
```

## 隐私与安全

- Bark Device Key 等同于推送凭据，不要提交到 Git 或粘贴到公开日志。
- 本插件只读取 Xboard 服务器状态，并写入自己的告警状态表。
- Bark 请求失败时不会将该次告警标记为已发送，下次调度会重试。

## License

[MIT](LICENSE)
