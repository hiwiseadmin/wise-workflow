# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.3] - 2026-11-25

### Changed
- 审批身份只认登录主体，不再接受请求体中的 `user_id` / `from_user`；未登录返回 401。
- 权限判定改为默认拒绝；`rollback` / `delegate` / `cancelTasks` 纳入统一授权，HTTP 路由默认关闭，需显式开启并配置认证（网关鉴权场景可设 `web.allow_anonymous`）。
- SLA 开关真正生效；系统触发标记与 `auto_sla` 由引擎写入，调用方不可伪造。
- 响应默认裁剪敏感字段；守卫表达式启用严格模式，未知键不再恒真。
- 转换确认改为服务端强制二次确认；`confirm=true` 的转换需显式传 `confirmed`。
- 写入纳入事务，乐观锁冲突不再重复写历史或触发事件。

### Fixed
- 修复模型 `$schema` 声明导致工作流不可用、状态二次解码、SLA 幂等字段缺失、通知监听器无法加载等问题。
- 修复任务归属/会签判定、HTTP 路径归属校验失效，以及常驻运行时并发串扰。

## [1.0.0] - 2024-01-01

- Initial v2.0 release

[1.0.0]: https://github.com/hiwiseadmin/wise-workflow/releases/tag/v1.0.0

[Unreleased]: https://github.com/hiwiseadmin/wise-workflow/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/hiwiseadmin/wise-workflow/releases/tag/v1.0.3
