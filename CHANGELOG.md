# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Security（安全审计阶段 3，含行为变更与一处新增默认值 ⚠️）

- **可信主体（⚠️ 行为变更）**：`WorkflowEngine::apply()` 现在会**剥离**调用方传入的 `context['user_id']`，只认注入的 `contract\PrincipalResolverInterface`；包内默认装配 `support\SessionPrincipalResolver`（读 `wise-workflow.user_session_key`）。此前调用方可在 `POST context` 中自带 `user_id`，而 `GuardListener::withUserId()` 见到该键就直接采用，可伪造守卫/权限判定。**迁移**：使用 JWT / 网关注入身份的项目请绑定自己的 `PrincipalResolverInterface` 实现；依赖「请求体自带 user_id」的调用方需改为登录态。
- **实例归属校验（可选，默认不生效）**：新增 `contract\SubjectOwnershipInterface`。宿主绑定后，引擎在状态写入前（`apply` / `rollback` / `delegate` / `cancelTasks`）校验归属，控制器读路径（`status` / `history` / `tasks`）同样校验；未绑定则行为不变。归属校验**先于**权限判定，`permission.allow_all` 不会跳过它。
- **响应字段裁剪（⚠️ 行为变更）**：控制器成功响应统一收口，递归剔除 `guard_expression` / `sla_config` / `ip` / `user_agent`（配置 `response.sensitive_fields`）。**迁移**：需要读取这些字段的前端 / 运维接口，请将对应键从裁剪列表移除，或设置 `response.reveal_sensitive_fields = true`（旧行为，不推荐）；如需「管理员可见」，请在中间件中按请求覆写该配置。
- **创建任务入参收敛**：`assignees` 先 `intval` + 去重，超过 `task.max_assignees`（默认 50）或 `policy` 不在 `all/any/ratio/count` 中时，控制器返回 422、`TaskManager` 抛 `WorkflowException`（双层校验）。此前单请求可按数组基数写入任意数量的任务行。
- **系统触发标记家族统一只认引擎写入**：`context['operator']` 与 `context['auto_sla']` 一样会被引擎剥离（阶段 2 引入 `operator = system:<trigger>` 记录时漏掉了这一条）。此前调用方可在请求体中传 `operator` 污染历史审计上下文，使「谁改的」不可信。

### Security（安全审计阶段 2，含破坏性变更 ⚠️）

- **`rollback` / `delegate` 接入统一授权点（⚠️ 破坏性）**：这两条路径此前完全绕过管道与权限判定（同一效果存在免检路径）。现在引擎在写入前调用统一授权点；新增**可选**契约 `contract/OperationAuthorizerInterface`（按操作区分授权），未实现时退化为「用合成的 Transition 调用 `check()`」，既有权限实现无需改动即可生效。**迁移**：若业务此前依赖「无需权限即可回退/改派」，需让权限实现放行 `__rollback__` / `__delegate__`。
- **`cancelTasks` 增加动作人并授权（⚠️ 破坏性）**：`WorkflowEngine::cancelTasks()` 新增第 3 个参数 `?int $actorId`，撤销前调用授权点（操作名 `__cancel_tasks__`）；HTTP 动作要求已登录，未登录返回 401。理由：撤销待办会解除 `CheckPendingTasksPipe` 这一**唯一**的会签闸门，与「提交审批」不是同一强度。
- **`sla.enabled` 真正生效（⚠️ 行为变更）**：`SlaManager::checkAll()/checkWorkflow()` 在开关关闭时直接返回空，不再扫描、不再自动 reject/escalate。命令行新增 `--force`（或 `setForce(true)`）显式覆盖，关闭时输出明确提示。**迁移**：此前把开关留 `false` 却仍期望 SLA 动作执行的定时任务，需要把开关设为 `true` 或加 `--force`。
- **系统触发流转记录操作者**：引擎在 `$systemTrigger` 非空时向历史上下文写入 `operator = system:<trigger>`（如 `system:auto_reject`），补上「谁改的」的审计信息。
- **事件路径补失败补偿**：`TaskManager::completeTask()` 的事件分支失败时，把任务恢复为 `pending` 再抛出（此前只有直接调用分支有补偿，事件分支会让任务停在 completed 而状态未流转，且 pending 守卫使重试不可能）。注意：事件服务若为异步/队列投递，补偿需由队列侧重试承担。
- **守卫上下文白名单默认启用（⚠️ 破坏性）**：`expression.allowed_context_keys` 默认只放行包自身的键（`transition_name`、`workflow_name`、`definition_version`、`auto_sla`、`operator`、`subject`、`user_id`、`remark`、`assigned_to`、`to_user`）。未列入白名单的 `context.<key>` 在守卫中解析为 `null`（即条件为假），并记录一条告警日志。**迁移**：业务若在 `guard_expression` 中读取自定义 context 键（如 `amount`、`department_id`），必须显式加入该列表，否则该守卫恒假；置 `null` 可退回「不限制」的旧行为。

### Security（安全审计阶段 1，含破坏性变更 ⚠️）

- **权限判定改为 fail-closed（⚠️ 破坏性）**：`CheckPermissionPipe` 在未注入权限服务时不再静默放行，而是拒绝转换。包内新增 `permission/DenyAllPermission` 与 `permission/AllowAllPermission` 兜底实现，`WorkflowServiceProvider` 在未绑定 `permission` 时按 `wise-workflow.permission.allow_all`（默认 `false`）绑定安全默认。**迁移**：业务需绑定自己的 `PermissionServiceInterface` 实现；确实不需要权限系统的部署请显式设置 `permission.allow_all = true`。
- **`auto_sla` 不再可由调用方伪造（⚠️ 破坏性）**：`WorkflowEngine::apply()` 新增第 5 个参数 `?string $systemTrigger`，并**剥离**调用方 context 中的 `auto_sla`；SLA 动作改由该参数传入受控标记。**迁移**：自行以 `apply(..., ['auto_sla' => ...])` 触发系统转换的代码，改用第 5 个参数。
- **HTTP 路由默认关闭（⚠️ 破坏性）**：`web.enable` 默认由 `true` 改为 `false`（改为 opt-in）；分组启用但未声明任何中间件时，启动期会输出安全告警（框架中「不声明中间件」等价于匿名可达）。**迁移**：需要 HTTP 接口的宿主需在 `config/wise-workflow.php` 显式开启并声明认证。
- **任务归属校验（⚠️ 破坏性）**：`TaskManager::completeTask()` 要求完成人等于任务 `assignee`，且**不再改写** `assignee`；`delegateTask()` 开始使用此前被忽略的 `$fromUserId` 校验发起人。**迁移**：代他人审批/转交由具备显式权限的服务端流程完成。
- **`userTasks` 不再回退请求参数**：会话不可用时返回 401，不再把 `?user_id=` 当作身份来源。
- **异常响应不再回显内部诊断**：`WorkflowController` 的 `WorkflowException` 分支不再返回 `getData()`，并把实例实际状态、版本号、乐观锁期望值、守卫表达式原文从 message 移入 `data`（只进日志）。
- **守卫表达式严格模式**：新增 `wise-workflow.expression.strict`（默认 `true`），表达式出现未知标识符时显式报错（此前会因 `(bool)` 判定**恒为真**，漏写 `context.` 前缀即等于条件永远通过）；置 `false` 时按 `false` 处理。
- **`history` 分页夹取**：`limit` 服务端限制在 `1..200`（此前 `limit=0` 会退化为不带 `LIMIT` 子句的查询）。
- **落库顺序修正**：`OptimisticLockSavePipe` 改为「先落库、再执行历史与事件工序」，并在默认/精简管道中排到 `RecordHistoryPipe` 之前——乐观锁冲突不再在副作用提交之后抛出，Engine 重试也不会重复写入历史与重复触发事件。

### Fixed
- **修复 4 个模型的 `$schema` 声明形态错误（引擎完全不可用的根因）**：`WorkflowMarking` / `WorkflowTask` / `WorkflowHistory` / `WorkflowDefinition` 把 `Model::$schema` 写成了「字段名扁平数组」，而 think-orm 的语义是**字段类型映射**（`array_keys()` 被用于推导写入白名单，见 `Model::checkAllowFields()`）。扁平数组会让白名单退化成 `[0, 1, 2 …]`，任何真实字段写入都被 `Builder::update()` 以 `fields not exists:[marking]` 拒绝 —— `Workflow::init()` 直接抛异常，工作流实例无法建立。现改为由数据库内省 + `$type` 提供字段与类型（与仓库其它模型口径一致）。
- 修复 `DatabaseMarkingStore::getMarking()` 对 `marking` 字段的**二次 JSON 解码**：模型已声明 `'marking' => 'json'`（读取时自动解码为数组），此处再 `json_decode()` 会抛 `TypeError`。现同时兼容数组与 JSON 字符串两种形态。
- 修复 SLA 幂等字段 `last_sla_at` 缺失：`SlaManager` 依赖该字段避免同一超时周期内重复触发，但其未在 `workflow_marking` 表结构与 `WorkflowMarking` 模型中定义。现新增迁移补充该列并在模型中声明，同时兼容全新安装与已有环境的升级。
- 修复开箱即用接线缺口（`WorkflowServiceProvider`）：将 `TaskManager` 绑定改为闭包并自动注入事件服务；新增完整装配的 `WorkflowEngine` 绑定供包内 Facade 与监听器使用；`boot()` 中注册 `TaskCompletionListener`（监听 `workflow.task_completed` 自动驱动状态流转）与受 `notification.enabled` 控制的 `DatabaseNotificationListener`（监听 `workflow.*`）。三者共享同一 think\Event 单例，触发与监听贯通。
- 统一权限检查契约：新增 `contract/PermissionServiceInterface`（`check(Transition $transition, array $context)`），`GuardListener` 优先走统一契约、未实现接口的旧实现自动回退 `check($userId, $permissionKey)`，消除两套语义并存导致的混淆；系统自动触发（`auto_sla`）路径跳过用户权限校验。
- 补全 SLA `auto_reject` / `escalate` 真实实现：`SlaManager` 注入可选 `WorkflowEngine`，超时后真正驱动状态流转；`wise:workflow:sla-check` 命令自动注入引擎，未注入时退化为通知以保持兼容。
- 重写 README 对齐真实 V2 Pipeline 架构：修正事件名（`workflow.{name}.before/after.{transition}` 等前缀事件，删除错误的 `before_transition`/`after_transition`）、修正版本声明（PHP>=8.1、ThinkPHP ^6.0||^8.0）、删除包内不存在的「增强版 Facade」API、补全 pipes/SLA/TaskManager/权限契约/版本策略/乐观锁/ExpressionGuard/MCP 等缺失章节与真实目录结构。
- 统一版本号默认值口径：`Workflow::getVersion()` 与 `WorkflowRegistry` 加载定义时的默认版本由 `0` 改为 `1`，与实例行乐观锁版本基线一致。

## [2.0.0] - 2024-01-01

### Added
- Initial v2.0 release

[2.0.0]: https://github.com/wiseadmin/wise-workflow/releases/tag/v2.0.0
