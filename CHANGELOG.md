# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## [0.1.0] - 2026-08-15

### Added

- 监控 Xboard 服务器心跳和负载数据新鲜度。
- 支持 CPU、内存、Swap、磁盘占用阈值及异常持续时间。
- 通过 Bark V2 API 发送告警、重复提醒和恢复通知。
- 将告警状态持久化，避免服务重启后重复通知。
- 提供手动检查与 Bark 测试 Artisan 命令。
