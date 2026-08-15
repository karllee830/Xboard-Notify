# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## [0.3.0] - 2026-08-15

### Changed

- Bark 通知级别改为下拉选择，可选“重要警告（critical）”或“时效性通知（timeSensitive）”。

## [0.2.0] - 2026-08-15

### Added

- Bark 相关配置保存成功后，默认使用新配置发送测试通知。
- 配置页面响应会显示测试通知成功、失败或因 Device Key 为空而跳过的结果。
- 可通过“保存 Bark 配置后发送测试通知”开关关闭自动测试。

## [0.1.0] - 2026-08-15

### Added

- 监控 Xboard 服务器心跳和负载数据新鲜度。
- 支持 CPU、内存、Swap、磁盘占用阈值及异常持续时间。
- 通过 Bark V2 API 发送告警、重复提醒和恢复通知。
- 将告警状态持久化，避免服务重启后重复通知。
- 提供手动检查与 Bark 测试 Artisan 命令。
