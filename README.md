# Wise-Workflow 工作流引擎

> 适用：WiseAdmin 1.0+ / PHP 8.1+ / ThinkPHP 6/8
> 状态：稳定（V2 Pipeline 洋葱模型）
> 可独立使用：是 —— 需迁移；默认权限 DenyAll，演示可开启 `permission.allow_all`
> 直接依赖：wise-support

> 基于 ThinkPHP 6/8 的轻量级工作流引擎，深度集成 WiseAdmin 框架的 RBAC、插件系统和事件系统  
> 设计思想参考 [Symfony Workflow](https://symfony.com/doc/current/components/workflow.html)，专为 WiseAdmin 生态定制  
> 版本：V2（Pipeline 洋葱模型架构） | PHP >= 8.1 | ThinkPHP ^6.0 || ^8.0

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)

---

## 目录

- [概述](#概述)
- [核心概念](#核心概念)
- [V2 架构：Pipeline 洋葱模型](#v2-架构pipeline-洋葱模型)
- [安装与配置](#安装与配置)
- [快速上手](#快速上手)
- [API 参考](#api-参考)
  - [定义工作流](#定义工作流)
  - [注册工作流](#注册工作流)
  - [引擎操作](#引擎操作)
  - [Facade 门面](#facade-门面)
  - [Pipeline 管道](#pipeline-管道)
  - [权限与授权](#权限与授权)
  - [安全装配清单](#安全装配清单上线前必须回答)
  - [事件系统](#事件系统)
  - [SLA 超时](#sla-超时)
  - [并行审批（TaskManager）](#并行审批taskmanager)
  - [版本策略与乐观锁](#版本策略与乐观锁)
  - [声明式守卫 ExpressionGuard](#声明式守卫-expressionguard)
- [HTTP 接口](#http-接口)
- [CLI 命令](#cli-命令)
- [插件集成指南](#插件集成指南)
- [常见问题](#常见问题)
- [升级与迁移（安全审计阶段 1–3）](#升级与迁移安全审计阶段-13)
- [依赖](#依赖)
- [许可证](#许可证)

---

## 概述

**Wise-Workflow** 是 WiseAdmin 生态的工作流引擎组件，采用**状态机模型（Place-Transition）**，可为任意业务对象（如订单、工单、审批单等）添加状态流转能力。它提供了完整的工作流定义、状态转换、权限检查、事件触发、SLA 超时、并行审批和历史记录等功能。

引擎核心 `WorkflowEngine::apply()` 在 V2 中升级为 **Pipeline（管道）架构**：一次状态转换被拆分为 13 个可编排的「管道工序」，插件可通过扩展点注册自定义工序插入任意位置，实现高度可扩展的流程控制。

### 设计理念

| 设计原则 | 说明 |
|---------|------|
| **轻量级** | 只实现 80% 项目需要的功能，避免功能冗余 |
| **深度集成** | 与 WiseAdmin 的 RBAC、插件系统、事件系统、数据库层无缝对接 |
| **易用性** | 插件开发者使用成本低，与 ThinkPHP 框架风格一致 |
| **可扩展性** | Pipeline 管道 + 扩展点，支持自定义工序、守卫、存储、事件监听 |

### 功能特性

- 基于 Place-Transition 模型的状态机工作流引擎
- 从数据库动态加载工作流定义，也支持代码静态注册
- **V2 Pipeline 洋葱模型**：12 道工序可编排、插件可注入自定义工序
- 深度集成 RBAC 权限系统，每个转换均可绑定权限校验（统一 `PermissionServiceInterface` 契约）
- **5 级事件生命周期**：`before` → `guard_failed` → `after` → `enter`/`leave` → `completed`，事件名带工作流前缀
- 完整的转换历史记录（操作人、IP、上下文）
- 支持 **声明式守卫表达式（ExpressionGuard）** 与回调守卫
- **乐观锁并发控制**：基于版本号 + 自动重试，避免并发改写冲突
- **工作流版本策略**：`strict` / `migrate` / `legacy`，防止定义变更破坏存量实例
- **SLA 超时**：`notify` / `auto_reject` / `escalate` 三种动作，可真正驱动状态流转
- **并行审批**：`TaskManager` 管理会签/或签任务，全部完成后自动驱动状态流转
- **回滚（rollback）/ 委托（delegate）** 支持（**与转换一样受授权约束**，不再有免检路径）
- **MCP 集成**：可将工作流能力以 MCP 工具暴露（需安装 `wiseadmin/wise-mcp`）
- 支持自定义状态存储（默认数据库，可扩展 Redis 等）

> **安全基线（安全审计阶段 1–3 已实施）**：权限缺省为**拒绝**、HTTP 路由与 SLA 开关为 **opt-in**、系统触发标记与 `user_id` **不可由请求体伪造**、`rollback`/`delegate`/`cancelTasks` 走统一授权点、可选**实例归属校验**、响应自动剔除内部策略字段。破坏性变更与迁移见 [升级与迁移](#升级与迁移安全审计阶段-13)。

---

## 核心概念

| 概念 | 英文 | 类 | 说明 |
|------|------|-----|------|
| **工作流** | Workflow | `wise\workflow\Workflow` | 工作流定义，包含名称、状态、转换、初始状态、元数据（含 `version`/`version_policy`/`sla_config`） |
| **状态节点** | Place | `wise\workflow\model\Place` | 工作流中的一个状态，如 `draft`（草稿）、`reviewing`（审核中） |
| **转换规则** | Transition | `wise\workflow\model\Transition` | 状态间的转换路径，定义 from → to，支持守卫表达式与元数据 |
| **标记** | Marking | `array` | 当前对象所处的工作流状态集合（如 `['draft' => 1]`） |
| **引擎** | WorkflowEngine | `wise\workflow\WorkflowEngine` | 核心执行引擎，负责 `apply()`/`can()`/`dryRun()`/`init()` 等 |
| **注册表** | WorkflowRegistry | `wise\workflow\WorkflowRegistry` | 全局工作流管理中心，支持静态注册与数据库动态加载 |
| **状态存储** | MarkingStore | `wise\workflow\contract\MarkingStoreInterface` | 持久化状态接口（默认实现：`DatabaseMarkingStore`） |
| **管道** | TransitionPipeline | `wise\workflow\TransitionPipeline` | V2 洋葱模型编排器，串联各管道工序 |
| **权限契约** | PermissionServiceInterface | `wise\workflow\contract\PermissionServiceInterface` | 转换级权限契约：`check(Transition, context)` |
| **操作级授权契约** | OperationAuthorizerInterface | `wise\workflow\contract\OperationAuthorizerInterface` | 可选契约：`authorizeOperation($operation, $subject, $context)`，用于 `rollback` / `delegate` / `cancelTasks` 这类非转换操作 |
| **可信主体契约** | PrincipalResolverInterface | `wise\workflow\contract\PrincipalResolverInterface` | 提供 `user_id`（谁在操作）。默认实现读会话；引擎会剥离请求体里的 `user_id` |
| **实例归属契约** | SubjectOwnershipInterface | `wise\workflow\contract\SubjectOwnershipInterface` | 可选契约：`isOwnedBy($subject, $userId, $context)`，绑定后写入前与读路径都会校验 |
| **守卫** | GuardListener | `wise\workflow\listener\GuardListener` | 权限守卫 callable，可作为 Transition 的 guard 使用 |
| **任务管理** | TaskManager | `wise\workflow\task\TaskManager` | 并行审批任务管理（创建/完成/委托/取消） |
| **SLA 管理** | SlaManager | `wise\workflow\sla\SlaManager` | SLA 超时检测与动作执行（受 `sla.enabled` 约束） |
| **响应裁剪** | ResponseSanitizer | `wise\workflow\support\ResponseSanitizer` | 成功响应出口统一剔除内部策略字段与操作者痕迹 |
| **HTTP 控制器** | WorkflowController | `wise\workflow\controller\WorkflowController` | 本包的 RESTful 端点（路由由 `RouteRegistrar` 配置驱动注册） |

---

## V2 架构：Pipeline 洋葱模型

一次 `apply()` 的状态转换被拆分为按序执行的管道工序（默认 12 道）：

```
apply(workflow, subject, transition, context)
   │
   └─ TransitionPipeline (洋葱模型)
        FindTransition            → 查找转换规则
        CheckPendingTasks         → 检查待处理的并行审批任务（未完成则阻塞）
        LoadMarking              → 读取当前状态
        VerifyVersion            → 工作流定义版本校验
        DispatchBeforeEvent      → 触发 workflow.{wf}.before.{transition}
        CheckPermission          → 权限校验（PermissionServiceInterface）
        EvaluateGuards          → 守卫表达式 / 回调守卫（失败触发 guard_failed）
        ValidateFromPlaces      → 校验源状态
        TransitionState         → 执行状态变更
        RecordHistory           → 记录历史
        DispatchAfterEvents     → 触发 after / leave.{place} / enter.{place} / completed
        OptimisticLockSave      → 乐观锁并发保存
```

插件可通过 `config('wise-workflow.pipelines')` 配置管道组合，或通过 `WorkflowRegistry::registerPipe()` / 扩展点 `WORKFLOW_PIPE` 注册自定义工序插入任意位置（命名插槽 `before_xxx` / `after_xxx`）。

### 包目录结构（真实的 src/ 布局）

```
wise-workflow/
├── composer.json
├── CHANGELOG.md
├── config/
│   └── wise-workflow.php                   # 默认配置（connection / cache / notification / sla / lock /
│                                            #   pipelines / pipe_slots / permission / response / task /
│                                            #   expression / web）
├── src/
│   ├── Workflow.php                        # 工作流定义类
│   ├── WorkflowEngine.php                  # 核心引擎（Pipeline + 统一授权点 + 可信主体 + 归属校验）
│   ├── WorkflowRegistry.php                # 注册表（静态注册 + 数据库加载）
│   ├── TransitionPipeline.php              # 管道编排器（洋葱模型）
│   ├── RouteRegistrar.php                  # HTTP 路由注册（配置驱动，默认关闭）
│   ├── WorkflowServiceProvider.php         # 容器绑定 + 事件监听 + 命令 + 路由注册
│   ├── command/
│   │   └── SlaCheck.php                    # SLA 超时检查命令（--workflow / --dry-run / --force）
│   ├── contract/
│   │   ├── MarkingStoreInterface.php       # 状态存储契约
│   │   ├── TransitionPipeInterface.php     # 管道工序契约
│   │   ├── TransitionPayload.php           # 管道载荷
│   │   ├── PermissionServiceInterface.php  # 转换级权限契约 check(Transition, context)
│   │   ├── OperationAuthorizerInterface.php# 操作级授权契约（可选：rollback/delegate/cancelTasks）
│   │   ├── PrincipalResolverInterface.php  # 可信主体契约（谁在操作）
│   │   └── SubjectOwnershipInterface.php   # 实例归属契约（这个实例归谁）
│   ├── controller/
│   │   └── WorkflowController.php          # HTTP API（读端点 + 状态变更 + 任务操作）
│   ├── event/
│   │   └── TaskCompletedEvent.php          # 并行审批完成事件对象
│   ├── exception/
│   │   ├── WorkflowException.php
│   │   └── ErrorCode.php
│   ├── facade/
│   │   ├── Workflow.php                    # WorkflowEngine 门面
│   │   ├── TaskManager.php                 # TaskManager 门面
│   │   └── WorkflowRegistry.php            # 注册表门面
│   ├── guard/
│   │   └── ExpressionGuard.php             # 声明式守卫表达式（严格模式 + 上下文白名单）
│   ├── listener/
│   │   ├── GuardListener.php               # 权限守卫 callable
│   │   ├── NotificationListener.php        # 通知监听基类（同文件含 DatabaseNotificationListener 数据库实现）
│   │   └── TaskCompletionListener.php      # task_completed → 自动驱动状态流转
│   ├── mcp/
│   │   └── WorkflowToolProvider.php        # MCP 工具提供者（可选）
│   ├── model/
│   │   ├── Place.php / Transition.php      # 状态节点 / 转换规则
│   │   ├── WorkflowDefinition.php          # 工作流定义模型
│   │   ├── WorkflowMarking.php             # 状态标记模型
│   │   ├── WorkflowHistory.php             # 历史记录模型
│   │   └── WorkflowTask.php                # 并行审批任务模型
│   ├── permission/
│   │   ├── DenyAllPermission.php           # 兜底：全拒（未绑定 permission 时的默认）
│   │   └── AllowAllPermission.php          # 兜底：全放行（allow_all = true 时）
│   ├── pipes/                              # 13 道默认工序（落库工序排在副作用工序之前）
│   │   ├── FindTransitionPipe.php
│   │   ├── CheckPendingTasksPipe.php
│   │   ├── LoadMarkingPipe.php
│   │   ├── VerifyVersionPipe.php
│   │   ├── DispatchBeforeEventPipe.php
│   │   ├── CheckPermissionPipe.php
│   │   ├── EvaluateGuardsPipe.php
│   │   ├── ValidateFromPlacesPipe.php
│   │   ├── CheckConfirmPipe.php            # 服务端强制二次确认（confirm=true 的转换）
│   │   ├── TransitionStatePipe.php
│   │   ├── OptimisticLockSavePipe.php
│   │   ├── RecordHistoryPipe.php
│   │   └── DispatchAfterEventsPipe.php
│   ├── sla/
│   │   └── SlaManager.php                  # SLA 超时管理（受 sla.enabled 约束）
│   ├── store/
│   │   └── DatabaseMarkingStore.php        # 数据库状态存储（含乐观锁）
│   ├── support/
│   │   ├── ResponseSanitizer.php           # 响应字段裁剪
│   │   └── SessionPrincipalResolver.php    # 默认可信主体（读会话）
│   └── task/
│       └── TaskManager.php                 # 并行审批任务管理
├── migrations/                             # 数据库迁移（definition / marking / history / task）
└── README.md
```

---

## 安装与配置

### 前置条件

- PHP >= 8.1
- ThinkPHP ^6.0 || ^8.0
- wise-support（提供扩展点、迁移等基础设施）
- MySQL 5.7+（或兼容数据库）
- [可选] WiseAdmin 框架；[可选] wiseadmin/wise-mcp（MCP 集成）；[可选] 权限包（RBAC）

### 通过 Composer 安装

```bash
composer require wiseadmin/wise-workflow
```

### 数据库迁移

包内置迁移文件，使用 ThinkPHP 迁移管理：

```bash
php think migrate:run
```

会创建 `workflow_definition`、`workflow_marking`、`workflow_history`、`workflow_task` 四张表。

### ServiceProvider 自动装配

`WorkflowServiceProvider` 通过 Composer 自动发现加载，在容器中完成以下绑定（开箱即用的完整装配）：

| 绑定 | 说明 |
|------|------|
| `MarkingStoreInterface` → `DatabaseMarkingStore` | 状态存储实现 |
| `ExpressionGuard` | 声明式守卫；**并在此装配上下文白名单**（`expression.allowed_context_keys`） |
| `permission`（容器标识） | 宿主未绑定时提供兜底实现：`DenyAllPermission`（默认）或 `AllowAllPermission`（`permission.allow_all = true`） |
| `PrincipalResolverInterface` → `SessionPrincipalResolver` | **可信主体来源**；宿主已绑定时不覆盖（JWT / 网关注入身份的项目请绑自己的实现） |
| `TaskManager` | 任务管理器（自动注入事件服务） |
| `TransitionPipeline` | 管道编排器（注入 TaskManager） |
| `WorkflowEngine` | **完整装配的引擎**：注入事件服务、权限服务、任务管理器、主体解析器；宿主若绑定了 `SubjectOwnershipInterface` 也会一并注入 |
| `command\SlaCheck` | 注册 SLA 检查 CLI 命令 |

> 容器标识 `permission` 默认**不是**全放行：未绑定实现时包内兜底为 `DenyAllPermission`，即所有转换都被拒绝（fail-closed）。绑定自己的 `PermissionServiceInterface` 实现即可覆盖。

`boot()` 阶段还会：
- 将配置文件中的管道注册到统一扩展点 `WORKFLOW_PIPE`；
- 注册 **MCP 工具提供者**（仅当 `wiseadmin/wise-mcp` 已安装时生效，未安装则静默跳过）；
- 注册 Web 路由（`web.enable = true` 时才注册，默认为 `false`）；
- 接线**开箱即用事件监听**：
  - `TaskCompletionListener` 监听 `workflow.task_completed` → 并行审批完成后自动驱动状态流转；
  - 当 `notification.enabled = true` 时，`DatabaseNotificationListener` 监听 `workflow.*` 发出工作流通知。

### 关键配置项（`config/wise-workflow.php`）

```php
return [
    'connection'  => '',                       // 数据库连接（空 = 默认连接）
    'cache'       => ['enabled' => true, 'ttl' => 3600],

    // 工作流通知（默认关闭；开启后 DatabaseNotificationListener 监听 workflow.*）
    'notification' => [
        'enabled'   => false,
        'channel'   => 'database',             // database / mail / sms / wechat
        'templates' => [
            'transition' => '工作流 [{workflow}] 状态由 [{from}] 变更为 [{to}]',
            'delegate'   => '工作流 [{workflow}] 已由 [{from_user}] 委托给您处理',
            'sla_alert'  => '工作流 [{workflow}] 在 [{place}] 状态已超时 [{duration}]',
        ],
    ],

    // SLA（安全审计阶段 2：enabled 由「死开关」变为**生效**开关。
    // 关闭时 checkAll / checkWorkflow 不扫描也不动作；需要执行请设为 true 或命令加 --force）
    'sla' => [
        'enabled'         => false,
        'default_timeout' => 86400,            // 默认超时（秒），24 小时
        'default_action'  => 'notify',         // notify / auto_reject / escalate
        'cron_expression' => '*/5 * * * *',    // 建议的检查频率（供宿主定时任务参考）
    ],

    // 乐观锁：开启后 apply 基于版本号 + 自动重试（默认开启）
    'lock' => ['enabled' => true, 'max_retries' => 3],

    // 版本管理默认策略：strict / migrate / legacy
    'version_policy' => 'strict',

    // 管道工序组合
    // ⚠️ OptimisticLockSavePipe **必须排在 RecordHistoryPipe / DispatchAfterEventsPipe 之前**：
    //    该工序先落库再执行后续工序，冲突在落库时抛出，此时副作用尚未发生，重试不会重复写历史/事件。
    'pipelines' => [
        'default' => [
            \wise\workflow\pipes\FindTransitionPipe::class,
            \wise\workflow\pipes\CheckPendingTasksPipe::class,
            \wise\workflow\pipes\LoadMarkingPipe::class,
            \wise\workflow\pipes\VerifyVersionPipe::class,
            \wise\workflow\pipes\DispatchBeforeEventPipe::class,
            \wise\workflow\pipes\CheckPermissionPipe::class,
            \wise\workflow\pipes\EvaluateGuardsPipe::class,
            \wise\workflow\pipes\ValidateFromPlacesPipe::class,
            \wise\workflow\pipes\CheckConfirmPipe::class,        // 二次确认（confirm=true 时强制）
            \wise\workflow\pipes\TransitionStatePipe::class,
            \wise\workflow\pipes\OptimisticLockSavePipe::class,   // ← 落库（副作用之前）
            \wise\workflow\pipes\RecordHistoryPipe::class,        // ↓ 副作用
            \wise\workflow\pipes\DispatchAfterEventsPipe::class,
        ],
        'approval' => null,                    // null = 继承 default
        'simple'   => [                        // 无权限校验、无事件、无并行审批检查
            \wise\workflow\pipes\FindTransitionPipe::class,
            \wise\workflow\pipes\LoadMarkingPipe::class,
            \wise\workflow\pipes\ValidateFromPlacesPipe::class,
            \wise\workflow\pipes\CheckConfirmPipe::class,
            \wise\workflow\pipes\TransitionStatePipe::class,
            \wise\workflow\pipes\OptimisticLockSavePipe::class,
            \wise\workflow\pipes\RecordHistoryPipe::class,
        ],
    ],

    // 命名插槽：将自定义 Pipe 插入到指定管道前面/后面（{before|after}_{PipeBaseName}）
    'pipe_slots' => [
        // 'after_CheckPermission' => [\App\Workflow\Pipe\AuditPipe::class],
    ],

    // 权限服务装配（安全审计阶段 1）：未注入权限服务时默认拒绝（fail-closed）。
    // 包内提供 DenyAllPermission / AllowAllPermission 兜底；业务绑定自己的实现即可覆盖。
    'permission' => [
        'allow_all' => false,                  // true = 明确放弃权限系统（不推荐，风险自负）
    ],

    // HTTP 响应字段裁剪（安全审计阶段 3）：命中键名的字段从所有成功响应中递归剔除
    'response' => [
        'sensitive_fields'        => ['guard_expression', 'sla_config', 'ip', 'user_agent'],
        'reveal_sensitive_fields' => false,    // true = 不裁剪（旧行为，不推荐）
    ],

    // 并行审批（安全审计阶段 3）：单请求审批人基数上限（去重后），超出返回 422
    'task' => ['max_assignees' => 50],

    // 守卫表达式求值（安全审计阶段 1 + 2）
    'expression' => [
        // 未知标识符直接报错，避免「漏写 context. 前缀 → 条件恒为真」这类 fail-open
        'strict' => true,

        // 守卫可读取的 context **顶层键白名单**；null = 不限制（不推荐）。
        // 未列出的键在守卫中解析为 null（条件为假）并记录告警日志。
        // 业务自定义键（如 amount、department_id）必须显式加进来，否则该守卫恒假。
        'allowed_context_keys' => [
            'transition_name', 'workflow_name', 'definition_version',
            'auto_sla', 'operator', 'subject', 'user_id',
            'remark', 'assigned_to', 'to_user',
        ],
    ],

    // 可选：会话主体键（`PrincipalResolverInterface` 默认实现与 GuardListener 都读它）
    'user_session_key' => 'user.id',

    // Web 路由（安全审计阶段 1：改为 opt-in，默认关闭）
    'web' => [
        'enable'     => false,
        'prefix'     => 'api/workflow',
        'middleware' => [],                    // 全局默认中间件
        'groups'     => [
            'workflow' => [
                'enable'     => true,
                'middleware' => [],            // ⚠️ 默认为空 = 匿名可达，必须在此声明认证中间件
            ],
        ],
    ],
];
```

---

## 5 分钟最小示例

> 前置：需执行迁移；默认权限为 DenyAll，演示可设置 `permission.allow_all=true` 或绑定 `PermissionServiceInterface`；`user_id` 由 `PrincipalResolverInterface` 提供。

```bash
php think migrate:run
```

```php
use wise\workflow\Workflow as WorkflowDef;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\WorkflowRegistry;
use wise\workflow\facade\Workflow;

$workflow = new WorkflowDef('order_approval');
$workflow->addPlace(new Place('draft'));
$workflow->addPlace(new Place('submitted'));
$workflow->addTransition(new Transition('submit', 'draft', 'submitted'));
$workflow->setMetadata([
    'title'         => '订单审批',
    'initial_place' => 'draft',
    'version'       => 1,
]);

WorkflowRegistry::add($workflow);
Workflow::init('order_approval', 'order:1001');
Workflow::apply('order_approval', 'order:1001', 'submit', ['remark' => '提交审批']);

print_r(Workflow::getMarking('order:1001'));
```

## 快速上手

### 步骤一：定义工作流

```php
use wise\workflow\Workflow;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;

$workflow = new Workflow('order_approval');

$workflow->addPlace(new Place('draft'));       // 草稿
$workflow->addPlace(new Place('submitted'));   // 已提交
$workflow->addPlace(new Place('reviewing'));   // 审核中
$workflow->addPlace(new Place('approved'));    // 已通过
$workflow->addPlace(new Place('rejected'));    // 已拒绝

$workflow->addTransition(new Transition('submit',  'draft',     'submitted'));
$workflow->addTransition(new Transition('assign',  'submitted', 'reviewing'));
$workflow->addTransition(new Transition('approve', 'reviewing', 'approved'));
$workflow->addTransition(new Transition('reject',  'reviewing', 'rejected'));

$workflow->setMetadata([
    'title'          => '订单审批流程',
    'description'    => '标准订单审批工作流',
    'initial_place'  => 'draft',
    'version'        => 1,                       // 定义版本号（默认基线 1）
    'sla_config'     => [                        // 可选：SLA 超时配置
        'reviewing' => ['timeout' => 86400, 'action' => 'auto_reject', 'reject_transition' => 'reject'],
    ],
]);
```

### 步骤二：注册工作流

```php
use wise\workflow\WorkflowRegistry;

// 代码静态注册
WorkflowRegistry::add($workflow);

// 或为持久化定义，写入 workflow_definition 表后：
WorkflowRegistry::loadFromDatabase();
```

### 步骤三：使用工作流引擎

```php
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\facade\Workflow;   // 包内 Facade，等价于 WorkflowEngine

// 通过 Facade（推荐）
Workflow::init('order_approval', 'order:1001');

if (Workflow::can('order_approval', 'order:1001', 'submit')) {
    Workflow::apply('order_approval', 'order:1001', 'submit', [
        'remark' => '提交审批',
        // ⚠️ 不要在这里传 user_id / auto_sla / operator：
        //    user_id 由容器中的 PrincipalResolverInterface（默认读会话）提供，
        //    auto_sla 只认引擎参数（SLA 路径），调用方传入的一律被剥离。
    ]);
}

$marking = Workflow::getMarking('order:1001');           // ['submitted' => 1]
$enabled = Workflow::getEnabledTransitions('order_approval', 'order:1001'); // 下一步可用转换
```

> **注意**：包内只提供 `wise\workflow\facade\Workflow`（直接代理 `WorkflowEngine`）。`is()` / `exists()` / `history()` / `register()` 等「增强版」方法不在包内，如有需要请在业务层自行封装。

---

## API 参考

### 定义工作流

#### Place 类

```php
namespace wise\workflow\model;

class Place
{
    public function __construct(string $name, array $metadata = []);
    public function getName(): string;
    public function getMetadata(): array;
}
```

#### Transition 类

```php
namespace wise\workflow\model;

class Transition
{
    public function __construct(
        string $name,
        $from,                                  // string | array 源状态
        $to,                                    // string | array 目标状态
        ?callable $guard = null,                // 回调守卫
        array $metadata = [],
        ?string $guardExpression = null         // 声明式守卫表达式
    );
    public function getName(): string;
    public function getFrom(): array;
    public function getTo(): array;
    public function getGuard(): ?callable;
    public function getMetadata(): array;
}
```

#### Workflow 类

```php
namespace wise\workflow;

class Workflow
{
    public function __construct(string $name, array $places = [], array $transitions = [], $initialPlace = null);
    public function addPlace(Place $place): void;
    public function addTransition(Transition $transition): void;
    public function setPlaces(array $places): void;
    public function setTransitions(array $transitions): void;
    public function setInitialPlace(?string $initialPlace): void;
    public function setMetadata(array $metadata): void;
    public function getMeta(string $key, $default = null);          // 读取单个元数据
    public function getVersion(): int;                             // 默认 1
    public function getVersionPolicy(): string;                    // 默认 'strict'
    public function getName(): string;
    public function getPlaces(): array;
    public function getTransitions(): array;
    public function getInitialPlace();
    public function toArray(): array;
}
```

### 注册工作流

```php
namespace wise\workflow;

class WorkflowRegistry
{
    public static function add(Workflow $workflow): void;
    public static function get(string $name): Workflow;
    public static function all(): array;
    public static function has(string $name): bool;
    public static function clear(): void;
    public static function loadFromDatabase(): void;    // 从 workflow_definition 表加载
    public static function loadFromConfig(): void;
    public static function getWorkflowPipes(string $workflowName): array;
    public static function registerPipe(string $name, string $pipeClass): void;  // 注册自定义工序
}
```

### 引擎操作

```php
namespace wise\workflow;

class WorkflowEngine
{
    public function __construct(
        MarkingStoreInterface $markingStore,
        $eventService = null,          // 必须实现 trigger(string, array): void
        $permissionService = null,     // 实现 PermissionServiceInterface 的权限服务
        $historyModel = null,
        ?TaskManager $taskManager = null
    );

    // ── 状态流转 ──
    public function init(Workflow $workflow, string $subject, array $context = []): void;
    public function apply(
        Workflow $workflow,
        string $subject,
        string $transitionName,
        array $context = [],
        ?string $systemTrigger = null   // 仅系统调用（如 SLA）传入：写入 auto_sla 标记并跳过用户权限校验
    ): array;
    public function can(Workflow $workflow, string $subject, string $transitionName, array $context = []): bool;
    public function canAny(Workflow $workflow, string $subject, array $context = []): array;  // 返回可用 Transition 列表
    public function dryRun(Workflow $workflow, string $subject, string $transitionName, array $context = []): array;
    public function getEnabledTransitions(Workflow $workflow, string $subject): array;

    // ── 非转换操作（均先过统一授权点，绑定归属契约时还会校验实例归属）──
    public function rollback(Workflow $workflow, string $subject, array $context = []): array;
    public function delegate(Workflow $workflow, string $subject, int $toUserId, array $context = []): void;
    public function cancelTasks(string $subject, string $transitionName = '', ?int $actorId = null): bool;

    // ── 标记与历史 ──
    public function getMarking(string $subject): array;
    public function setMarking(string $subject, array $marking, array $context = []): void;  // ⚠️ 裸写通道，见下方说明
    public function getHistory(string $subject, int $limit = 50): array;

    // ── 并行审批任务（委托给 TaskManager）──
    public function createTasks(Workflow $workflow, string $subject, string $transitionName, array $assignees, string $policy = 'all', $policyValue = null, array $context = []): array;
    public function completeTask(string $subject, string $transitionName, string $taskKey, int $userId, string $result, array $context = []): bool;
    public function delegateTask(string $subject, string $transitionName, string $taskKey, int $fromUserId, int $toUserId): bool;
    public function getPendingTasks(string $subject): array;
    public function getUserTasks(int $userId, string $status = ''): array;

    // ── 安全注入点（安全审计阶段 3）──
    public function setPrincipalResolver(?PrincipalResolverInterface $resolver): void;  // 可信主体来源
    public function setOwnershipService(?SubjectOwnershipInterface $ownership): void;   // 实例归属判定
    public function checkSubjectAccess(string $subject): void;                          // 读路径归属校验入口

    // ── 装配 ──
    public function setTaskManager(TaskManager $taskManager): void;
    public function getTaskManager(): ?TaskManager;
    public function getDefaultPipes(): array;
    public function getPipeline(): TransitionPipeline;
}
```

`subject` 是流程实例的唯一标识，格式为 `{类型}:{ID}`（如 `order:1001`）。

> ⚠️ **`setMarking()` 是裸写通道**：它直接写状态存储，**不经过**管道、权限判定、版本校验与历史记录。包内不通过 HTTP 暴露它，仅供迁移/运维脚本使用；业务代码若对外暴露该方法，必须自行限制调用方（例如只允许已认证的管理员或后台命令）。

### Facade 门面

包内提供 `wise\workflow\facade\Workflow`，静态方法直接代理 `WorkflowEngine`：

| 方法 | 说明 |
|------|------|
| `Workflow::init($workflowName, $subject, $context = [])` | 初始化到初始状态 |
| `Workflow::apply($workflowName, $subject, $transition, $context = [])` | 执行状态转换 |
| `Workflow::can($workflowName, $subject, $transition)` | 是否可执行 |
| `Workflow::canAny($workflowName, $subject, $context = [])` | 是否存在任一可执行转换 |
| `Workflow::dryRun($workflowName, $subject, $transition, $context = [])` | 模拟执行（不落库） |
| `Workflow::rollback($workflowName, $subject, $context = [])` | 回滚 |
| `Workflow::delegate($workflowName, $subject, $toUserId, $context = [])` | 委托 |
| `Workflow::getMarking($subject)` | 获取当前状态 |
| `Workflow::setMarking($subject, $marking, $context = [])` | 设置状态 |
| `Workflow::getEnabledTransitions($workflowName, $subject)` | 可用转换列表 |
| `Workflow::getHistory($subject, $limit = 50)` | 历史记录 |

> 注意：`Workflow` Facade 的 `$workflowName` 参数为工作流名称字符串（内部通过 `WorkflowRegistry::get()` 解析为 `Workflow` 对象）。
>
> Facade 代理 `WorkflowEngine` 的公开方法，上表只列常用项；`rollback` / `delegate` / `cancelTasks` 等同样受统一授权点与实例归属校验约束。

### Pipeline 管道

每个管道工序实现 `TransitionPipeInterface::handle(TransitionPayload $payload, \Closure $next)`。默认执行顺序见 [V2 架构](#v2-架构pipeline-洋葱模型)。

**自定义管道示例：**

```php
namespace app\workflow\pipe;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;

class AuditPipe implements TransitionPipeInterface
{
    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        // 在权限校验之后追加审计逻辑
        $payload->log(__CLASS__, 'passed', '审计通过');
        return $next($payload);
    }
}
```

注册到 `CheckPermissionPipe` 之后：

```php
// config/wise-workflow.php
'pipe_slots' => [
    'after_CheckPermission' => [\app\workflow\pipe\AuditPipe::class],
],
```

### 权限与授权

包内所有权限检查点统一调用 `PermissionServiceInterface` 契约：

```php
namespace wise\workflow\contract;

use wise\workflow\model\Transition;

interface PermissionServiceInterface
{
    /**
     * @param Transition $transition 转换对象
     * @param array      $context    上下文。以下键由引擎/管道写入，**可信**：
     *                               subject / workflow_name / transition_name / definition_version /
     *                               user_id（来自 PrincipalResolverInterface）/ auto_sla（仅系统触发时）
     *                               调用方在请求体里传的 user_id、auto_sla 会被引擎剥离。
     * @return bool 允许返回 true，拒绝返回 false
     */
    public function check(Transition $transition, array $context): bool;
}
```

- `CheckPermissionPipe` 调用 `check($payload->transition, $payload->context)`；
- `GuardListener`（作为 Transition 的 guard callable 时）优先走同一契约（从注册表解析 `Transition` 对象后调用），未实现该接口的旧实现自动回退到 `check($userId, $permissionKey)` 以保持兼容；
- **未注入权限服务时默认拒绝**（fail-closed）：不再静默放行。确实不需要权限系统的部署请显式设置 `wise-workflow.permission.allow_all = true`（包内提供 `DenyAllPermission` / `AllowAllPermission` 两个兜底实现）；
- **系统自动触发**（如 SLA 超时自动 reject/escalate）会跳过用户权限校验：该标记由**引擎参数**写入受控上下文，调用方传入的 `context['auto_sla']` 会被剥离，**无法伪造**；
- **非转换类操作同样受授权约束**：`rollback` / `delegate` / `cancelTasks` 不再绕过权限判定，操作名为 `__rollback__` / `__delegate__` / `__cancel_tasks__`。权限服务可选择实现 `OperationAuthorizerInterface` 按操作区分授权；未实现时引擎用合成的 Transition 调用 `check()`，既有实现无需改动即生效；
- **`sla.enabled` 是生效开关**：关闭时 SLA 检查不扫描也不动作，定时任务需要执行请设为 `true` 或加 `--force`；系统触发的流转会在历史上下文留下 `operator = system:<trigger>`；
- **守卫上下文白名单默认启用**：`expression.allowed_context_keys` 未列出的 `context.<key>` 在守卫中解析为 `null`（条件为假）并记录告警；业务自定义键必须显式加入，置 `null` 可退回不限制。

### 安全装配清单（上线前必须回答）

| # | 问题 | 包内对应控制 | 不做的后果 |
|---|---|---|---|
| ① | 工作流 HTTP 接口对谁开放？ | `web.enable` 与 `web.groups.workflow.middleware`（**默认为空**，包不会自动挂认证） | 匿名可达（包内权限判定无法替代认证） |
| ② | `permission` 由谁实现？ | 绑定 `permission`（`PermissionServiceInterface`）；未绑定时包内兜底为 `DenyAllPermission` | 未绑定即**全拒**；设 `permission.allow_all = true` 则全放行 |
| ③ | 实例归属怎么判定？ | 绑定 `SubjectOwnershipInterface`（可选）；引擎写入前与读路径都会校验 | 不绑定则不校验归属：任何能过 ② 的主体都能操作任意 subject |
| ④ | 可信主体从哪来？ | 绑定 `PrincipalResolverInterface`（默认 `SessionPrincipalResolver` 读会话）；引擎会剥离请求体里的 `user_id` | 用 JWT/网关注入身份却不绑定，则 `context.user_id` 恒为空，守卫与权限实现拿不到主体 |
| ⑤ | SLA 开关是开还是关？ | `sla.enabled` + 命令 `--force`；关闭时 `checkAll/checkWorkflow` 不动状态 | 期望自动超时动作却什么都不发生（或反过来：悄悄自动改状态） |
| ⑥ | MCP 工具面对谁开放？ | 由 `wise-mcp` 自身配置（`security.require_auth`、`guard`、`web.enable`）决定；本包只注册工具提供者 | 若 wise-mcp 被配成公开无认证，`workflow.list` / `workflow.definition` 会暴露工作流定义（响应已剔除策略字段，但流程结构仍可见） |

补充建议（**运维侧，包内不实现**）：为 `workflow_history` / `workflow_task` 设定保留与归档策略，并按主体配额与限流阈值；`api` 应用自带的 `RateLimitMiddleware`（60 容量 / 1 QPS）不是按主体的额度。

业务侧实现示例：

```php
class RbacPermissionService implements
    \wise\workflow\contract\PermissionServiceInterface,
    \wise\workflow\contract\OperationAuthorizerInterface   // 可选：按操作区分授权
{
    public function check(\wise\workflow\model\Transition $transition, array $context): bool
    {
        $userId = $context['user_id'] ?? 0;                 // 可信值：来自 PrincipalResolverInterface
        $wfName = $context['workflow_name'] ?? '';
        return Rbac::can($userId, "workflow.{$wfName}.{$transition->getName()}");
    }

    public function authorizeOperation(string $operation, string $subject, array $context): bool
    {
        $userId = $context['user_id'] ?? 0;
        return match ($operation) {
            '__rollback__'     => Rbac::isAdmin($userId),                      // 回退已通过的审批
            '__delegate__'     => Rbac::can($userId, "workflow.delegate.{$subject}"),
            '__cancel_tasks__' => Rbac::isAdmin($userId),                      // 撤销会签要求
            default            => false,                                      // 未知操作一律拒绝
        };
    }
}
```

将权限服务绑定到容器的 `permission` 标识，引擎会自动注入。

#### 非转换操作：操作级授权

`rollback` / `delegate` / `cancelTasks` 不是「转换」，无法用 `Transition` 表达。引擎用**统一授权点**处理它们（安全审计阶段 2）：

| 权限服务实现 | 引擎的调用方式 |
|---|---|
| 实现了 `OperationAuthorizerInterface` | 调用 `authorizeOperation($operation, $subject, $context)`，可按操作区分 |
| 未实现（既有实现） | 用**合成的 Transition** 调用既有 `check()`，操作名即转换名：`__rollback__` / `__delegate__` / `__cancel_tasks__` |

两者都先过**实例归属校验**（若绑定了 `SubjectOwnershipInterface`），且 `permission.allow_all` 不会跳过归属校验。

> `cancelTasks` 会解除 `CheckPendingTasksPipe` 这一**唯一**的会签闸门，因此单独授权；HTTP 动作要求已登录（未登录 401），并需要会话身份作为动作人。

#### 可信主体与实例归属（可选绑定）

```php
// ① 可信主体：默认 SessionPrincipalResolver 读会话；JWT / 网关注入身份的项目请绑自己的实现
$this->app->bind(
    \wise\workflow\contract\PrincipalResolverInterface::class,
    \app\workflow\JwtPrincipalResolver::class      // getUserId(): int，匿名返回 0
);

// ② 实例归属：绑定后，apply / rollback / delegate / cancelTasks 写入前，
//    以及 status / history / tasks 读路径都会校验；未绑定则不校验（保持既有行为）
$this->app->bind(
    \wise\workflow\contract\SubjectOwnershipInterface::class,
    \app\workflow\OrderOwnership::class            // isOwnedBy($subject, $userId, $context): bool
);
```

### 事件系统

引擎在每次转换前后触发带**工作流前缀**的事件，支持细粒度监听：

| 事件名称 | 触发时机 | 参数 |
|---------|---------|------|
| `workflow.{name}.before.{transition}` | 转换执行前 | `{workflow, transition, subject, context}` |
| `workflow.{name}.after.{transition}` | 转换执行后 | `{workflow, transition, subject, context}` |
| `workflow.{name}.leave.{place}` | 离开某源状态 | `{workflow, transition, subject, place, context}` |
| `workflow.{name}.enter.{place}` | 进入某目标状态 | `{workflow, transition, subject, place, context}` |
| `workflow.{name}.completed` | 转换整体完成 | `{workflow, transition, subject, context}` |
| `workflow.{name}.guard_failed` | 守卫校验失败 | `{workflow, transition, subject, reason, context}` |
| `workflow.{name}.rollback` / `workflow.{name}.delegate` | 回滚 / 委托 | `{workflow, subject, context}` |
| `workflow.task_completed` | 并行审批任务全部完成 | `{subject, transition, context}` |

> 事件名格式为 `{workflow}.{name}.{阶段}.{转换/状态}`，**不是** `before_transition` / `after_transition`。

```php
use think\facade\Event;

// 监听特定工作流的具体转换完成
Event::listen('workflow.order_approval.approve', function ($params) {
    $subject = $params['subject'];
    // 更新业务表状态、发通知等
});

// 监听任意工作流完成
Event::listen('workflow.*', function ($params) {
    // 统一通知逻辑（受 notification.enabled 控制时由 DatabaseNotificationListener 处理）
});

// 在 before 事件中阻止非法转换
Event::listen('workflow.order_approval.before.approve', function ($params) {
    if (Order::find($params['subject'])->amount > 100000 && empty($params['context']['manager_approved'])) {
        throw new \Exception('大额订单需要经理审批');
    }
});
```

### SLA 超时

`SlaManager` 检测实例是否在配置的状态停留超时，支持三种动作：

- `notify`：仅发送通知（通过注入的 `notifier` 回调）；
- `auto_reject`：通过注入的 `WorkflowEngine` 执行 `reject_transition`（默认 `reject`）真正驱动状态流转；
- `escalate`：通过引擎执行 `escalate_transition`（默认 `escalate`），可同时指派处理人（`escalate_to`）。

SLA 配置放在工作流元数据的 `sla_config` 中，按 `place` 配置：

```php
$workflow->setMetadata([
    'initial_place' => 'reviewing',
    'sla_config' => [
        'reviewing' => [
            'timeout'           => 86400,                 // 超时秒数
            'action'            => 'auto_reject',         // notify | auto_reject | escalate
            'reject_transition' => 'reject',              // auto_reject 使用的转换
            // 'escalate_transition' => 'escalate',       // escalate 使用的转换
            // 'escalate_to'         => 10086,            // escalate 指派的处理人
        ],
    ],
]);
```

> 未注入 `WorkflowEngine` 时，`auto_reject` / `escalate` 退化为通知（保持兼容）；注入引擎后才能真实改变状态。`auto_sla` 上下文会自动跳过权限校验（该标记只认引擎参数，调用方无法伪造）。

> **`sla.enabled` 是生效开关**（安全审计阶段 2）：开关为 `false`（默认）时 `checkAll()` / `checkWorkflow()` **直接返回空**，不扫描也不执行任何自动动作；需要执行时把开关设为 `true`，或显式 `--force` / `setForce(true)`。系统触发的流转会在历史上下文中写入 `operator = system:<trigger>`（如 `system:auto_reject`），便于追溯「谁改的」。

**编程式调用：**

```php
use wise\workflow\sla\SlaManager;

$slaManager = new SlaManager(null, null, $notifier, $dryRun, $engine);
$slaManager->setForce(true);               // 忽略 sla.enabled 开关强制执行（可选）
$results = $slaManager->checkAll();        // 检查所有工作流超时实例
// 或 $slaManager->checkWorkflow($workflow);
```

### 并行审批（TaskManager）

`TaskManager` 管理会签/或签任务。`workflow.task_completed` 事件由 `TaskCompletionListener` 监听，自动调用 `WorkflowEngine::apply()` 驱动状态流转（开箱即用已接线）。

```php
use wise\workflow\task\TaskManager;
use wise\workflow\WorkflowRegistry;

$taskManager = new TaskManager();        // 或从容器取（已自动注入事件服务）

// ① 为某转换创建多名审批人的并行任务
$tasks = $taskManager->createTasks(
    WorkflowRegistry::get('order_approval'), // Workflow 定义对象
    'order:1001',                            // subject
    'approve',                               // 全部任务完成后触发的转换
    [1001, 1002, 1003],                      // assignees（自动去重取整，上限 task.max_assignees）
    'all',                                   // policy: all(会签) | any(或签) | ratio | count
    null,                                    // policy_value（ratio 为 0.0~1.0，count 为整数）
    ['remark' => '提交会签']                 // context
);

// ② 审批人完成任务（触发 workflow.task_completed → TaskCompletionListener 自动驱动流转）
$taskManager->completeTask(
    'order:1001',      // subject
    'approve',         // transition_name
    'approve_1',       // task_key（createTasks 按 {transition}_{序号} 生成）
    1001,              // 完成人：**必须是该任务的 assignee**
    'approved',        // approved | rejected
    ['comment' => '同意']
);

// ③ 查询与操作
$taskManager->getPendingTasks('order:1001');                          // 该实例全部待处理任务
$taskManager->getPendingTasksForTransition('order:1001', 'approve');   // 指定转换的待处理任务
$taskManager->getUserTasks(1001, 'pending');                          // 某用户的待办
$taskManager->cancelTasks('order:1001', 'approve');                    // 取消待处理任务
$taskManager->delegateTask('order:1001', 'approve', 'approve_1', 1001, 1002); // 转交
```

> **安全约束（安全审计阶段 1–3）**：
> - `completeTask` 的完成人**必须是该任务的 `assignee`**，否则抛异常（此前任何调用方都能以他人身份完成任务并改写 `assignee`）；
> - `delegateTask` 只有当前 `assignee` 能转交（`$fromUserId` 会校验归属）；
> - `createTasks` 的 `assignees` 会去重取整；超过 `task.max_assignees`（默认 50）或 `policy` 非法即拒绝（HTTP 层返回 422）；
> - **引擎的** `cancelTasks($subject, $transition, $actorId)` 会先过统一授权点（`__cancel_tasks__`）；直接调用 `TaskManager::cancelTasks()` 属于内部方法，绕过授权，请仅在受信任的服务端代码中使用；
> - 任务完成后若状态流转失败（含事件驱动路径），任务行会恢复为 `pending`，不会出现「任务已消耗但状态未流转」。

### 版本策略与乐观锁

- **乐观锁**：`DatabaseMarkingStore` 每行记录带 `version` 字段，`setMarking` 使用 `WHERE version = ?` 条件更新；并发冲突时 `WorkflowEngine::apply()` 自动重试（默认最多 3 次，见 `lock.max_retries`）。
- **定义版本校验**：`Workflow::getVersion()`（默认 `1`）写入实例的 `definition_version`；`VerifyVersionPipe` 在 `version_policy = strict`（默认）时比较实例与当前定义版本，不一致则拒绝执行（提示迁移）。
- **版本策略**：`strict`（不一致拒绝）/ `migrate`（自动迁移，业务层处理）/ `legacy`（旧实例沿用旧定义）。

```php
$workflow->setMetadata([
    'version'        => 2,            // 定义变更后递增
    'version_policy' => 'strict',     // 或 migrate / legacy
]);
```

> 建议：每次修改工作流定义结构后递增 `version`；存量实例会在下次转换时接受版本校验，未记录定义版本的存量实例自动跳过校验（兼容）。

### 声明式守卫 ExpressionGuard

除回调守卫外，Transition 支持 `guard_expression` 声明式表达式：

```php
$transition = new Transition(
    'auto_approve', 'reviewing', 'approved',
    null,
    [],
    'context.amount < 1000'          // 声明式守卫表达式
);
```

---

## HTTP 接口

路由由 `RouteRegistrar` 按配置注册：**`web.enable` 默认为 `false`，即本包默认不注册任何 HTTP 路由**（安全审计阶段 1）。开启后必须在 `web.groups.workflow.middleware` 中声明认证——框架中「不声明中间件」等价于**匿名可达**，而本组接口包含状态变更操作。

前缀默认 `api/workflow`（`web.prefix`），端点如下：

| 方法 | 路径 | 动作 | 说明 |
|---|---|---|---|
| GET | `definitions` | `definitions` | 所有工作流定义（响应已剔除 `guard_expression` / `sla_config`） |
| GET | `definitions/:name` | `definition` | 单个定义详情 |
| GET | `:name/:subject` | `status` | 实例状态 + 可用转换 + 待处理任务 |
| GET | `:name/:subject/history` | `history` | 历史记录（`limit` 服务端夹取 1–200；`ip` / `user_agent` 已剔除） |
| GET | `:name/:subject/tasks` | `tasks` | 待处理任务列表 |
| GET | `user-tasks` | `userTasks` | **当前登录用户**的待办（身份取自会话，未登录 401） |
| POST | `:name/:subject/apply` | `apply` | 执行转换（`transition` + `context`） |
| POST | `:name/:subject/rollback` | `rollback` | 回滚上一状态（需授权，未登录 401） |
| POST | `:name/:subject/delegate` | `delegate` | 委托给 `to_user`（需授权，未登录 401） |
| POST | `:name/:subject/task/create` | `createTasks` | 创建并行审批任务（`assignees` ≤ `task.max_assignees`，否则 422） |
| POST | `:name/:subject/task/:key/complete` | `completeTask` | 完成任务（`result` = `approved` / `rejected`） |
| POST | `:name/:subject/task/:key/delegate` | `delegateTask` | 转交任务 |
| POST | `:name/:subject/task/cancel` | `cancelTasks` | 取消待处理任务（需授权，未登录 401） |

响应使用 `wise-support` 的 `JsonResponse` 格式（`code` / `msg` / `data`），另有几点行为需注意：

- 业务异常（`WorkflowException`）只回显面向用户的 `msg`；**诊断信息（实例状态、版本号、守卫表达式原文）只进日志**，不返回给调用方；
- 成功响应统一经 `ResponseSanitizer` 剔除内部策略字段与操作者痕迹（见 `response.sensitive_fields`）；
- 读端点（`status` / `history` / `tasks`）在绑定了 `SubjectOwnershipInterface` 时会先校验实例归属。

---

## CLI 命令

```bash
# 检查所有工作流的 SLA 超时实例（建议配置为每 5 分钟定时任务）
php think wise:workflow:sla-check

# 指定工作流
php think wise:workflow:sla-check --workflow=order_approval

# 仅检测不执行实际动作
php think wise:workflow:sla-check --dry-run

# 忽略 sla.enabled 开关强制执行（安全审计阶段 2）
php think wise:workflow:sla-check --force
```

> SLA 命令会自动注入 `WorkflowEngine`（若容器已绑定），使 `auto_reject` / `escalate` 真正驱动状态流转；未注入时退化为通知。
>
> **开关**：`sla.enabled = false`（默认）时命令只输出提示并退出，**不会改动任何状态**。定时任务若需要执行，请把开关设为 `true`，或给命令加 `--force`。

---

## 插件集成指南

在 WiseAdmin 插件中使用工作流引擎：

```php
<?php
namespace plugin\myplugin;

use wise\workflow\Workflow;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\WorkflowRegistry;
use wise\workflow\facade\Workflow;

class Plugin
{
    public static function getWorkflowDefinitions(): array
    {
        $workflow = new Workflow('my_approval');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('submitted'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addPlace(new Place('rejected'));

        $workflow->addTransition(new Transition('submit',  'draft',     'submitted'));
        $workflow->addTransition(new Transition('approve', 'submitted', 'approved'));
        $workflow->addTransition(new Transition('reject',  'submitted', 'rejected'));

        $workflow->setMetadata([
            'title'         => '我的审批流程',
            'initial_place' => 'draft',
            'version'       => 1,
        ]);

        return [$workflow];
    }
}
```

在引导处注册并监听事件：

```php
// 注册工作流
foreach (Plugin::getWorkflowDefinitions() as $workflow) {
    WorkflowRegistry::add($workflow);
}

// 监听工作流事件
\think\facade\Event::listen('workflow.my_approval.after.approve', function ($params) {
    // 自定义逻辑：发送通知、更新数据等
});
```

控制器中操作：

```php
use wise\workflow\facade\Workflow;

public function submit()
{
    $id = $this->request->param('id');
    $record = MyModel::find($id);

    if (!Workflow::can('my_approval', "my:$id", 'submit')) {
        return $this->error('当前状态不允许提交');
    }

    Workflow::apply('my_approval', "my:$id", 'submit', [
        'remark' => '来自业务控制器',
        // 不要传 user_id：主体由 PrincipalResolverInterface（默认读会话）提供
    ]);

    return $this->success('提交成功');
}
```

---

## 常见问题

### Q: 如何判断当前工作流状态？

```php
$marking = Workflow::getMarking('order:1001');
if (isset($marking['draft'])) {
    echo '当前为草稿状态';
}
```

### Q: 如何获取当前可执行的操作列表？

```php
$transitions = Workflow::getEnabledTransitions('order_approval', 'order:1001');
foreach ($transitions as $transition) {
    echo $transition->getName(); // submit, approve, reject...
}
```

### Q: 如何自定义权限检查逻辑？

实现 `wise\workflow\contract\PermissionServiceInterface::check(Transition $transition, array $context)`，并将权限服务绑定到容器 `permission` 标识（引擎会自动注入）。

如果还要区分 `rollback` / `delegate` / `cancelTasks`，再实现可选的 `OperationAuthorizerInterface::authorizeOperation($operation, $subject, $context)`；不实现时引擎会用**合成的 Transition** 调用 `check()`（操作名即转换名），既有实现无需改动即生效。

### Q: 一个业务对象可以绑定多个工作流吗？

可以。每个工作流通过 `name` 区分，绑定到不同的 `subject`，互不影响。同一个业务对象可通过不同 subject 标识绑定不同工作流。

### Q: 如何回滚到上一个状态？

使用引擎原生 `rollback()`：

```php
Workflow::rollback('order_approval', 'order:1001', ['remark' => '业务侧回退']);
```

回滚会经过**统一授权点**（操作名 `__rollback__`）与**实例归属校验**（若已绑定归属契约），因此需要权限实现放行该操作；HTTP 路径还要求已登录。

### Q: 表前缀如何配置？

Wise-Workflow 使用 ThinkPHP 的数据库连接配置，自动适配项目中的表前缀（如 `ws_`）。

### Q: 并发提交会不会互相覆盖状态？

不会。`DatabaseMarkingStore` 基于 `version` 字段的乐观锁实现 `WHERE version = ?` 更新，`WorkflowEngine::apply()` 在冲突时自动重试（默认 3 次）。

### Q: 工作流定义改了，存量实例怎么办？

为定义设置 `version` 并配置 `version_policy`：`strict` 会拒绝版本不一致的转换（提示迁移）；`migrate` / `legacy` 由业务层自行兼容。

### Q: 升级后所有转换都被拒绝了？

包内权限缺省为 **fail-closed**：容器里没有 `permission` 实现时兜底为 `DenyAllPermission`。请绑定自己的 `PermissionServiceInterface` 实现；确实不需要权限系统（例如纯内部状态机）才设 `wise-workflow.permission.allow_all = true`。

### Q: 响应里为什么看不到 `guard_expression` / `sla_config` / `ip` / `user_agent`？

安全审计阶段 3 在成功响应出口统一裁剪了这些内部字段。需要时把它们从 `response.sensitive_fields` 移除，或设 `response.reveal_sensitive_fields = true`；若要「管理员可见、普通用户不可见」，请在自己的中间件中按请求覆写该配置。

### Q: 我在 `context` 里传的 `user_id` 为什么没生效？

`user_id` 只认容器中的 `PrincipalResolverInterface`（默认 `SessionPrincipalResolver` 读会话），请求体传入的一律被剥离——这是为了避免「伪造主体满足守卫/权限条件」。JWT / 网关注入身份的项目请绑定自己的实现。

### Q: `rollback` / `delegate` / `cancelTasks` 报「权限校验失败」怎么办？

它们与转换一样走统一授权点，操作名为 `__rollback__` / `__delegate__` / `__cancel_tasks__`。请在权限实现中放行对应操作（可实现 `OperationAuthorizerInterface` 按操作区分），或确认容器里确实绑定了权限实现。

### Q: 守卫突然恒假（转换总是被拒）？

检查 `expression.allowed_context_keys`：未列入白名单的 `context.<key>` 会解析为 `null`（条件为假）。日志中会有一条白名单告警指明具体键名，加进白名单即可；也可把该配置设为 `null` 退回「不限制」。

---

## 升级与迁移（安全审计阶段 1–3）

下面这些变更会改变既有行为，升级前请逐条确认（完整条目与实现细节见 `CHANGELOG.md`）。

### 需要处理的

| 变更 | 现象 | 迁移方式 |
|---|---|---|
| HTTP 路由默认关闭（`web.enable = false`） | 升级后接口 404 | 需要接口就显式开启，并在 `web.groups.workflow.middleware` 声明认证 |
| 未绑定 `permission` 即**全拒**（原为静默放行） | 所有转换被拒 | 绑定 `PermissionServiceInterface` 实现；确实不要权限系统则设 `permission.allow_all = true` |
| `context.user_id` 只认主体解析器 | 请求体传 `user_id` 不再生效 | 绑定自己的 `PrincipalResolverInterface`（默认实现读 `user_session_key`） |
| 守卫上下文白名单默认启用 | 未列出的 `context.<key>` 在守卫中恒假 | 把业务键加入 `expression.allowed_context_keys`（日志有告警可定位） |
| 守卫表达式严格模式（`strict = true`） | 写错的表达式**显式报错**，不再静默恒真 | 修正表达式；临时可设 `strict = false`（仍是 fail-closed，只是不报错） |
| 管道顺序调整（落库工序前移） | 自定义管道若沿用旧顺序仍会重放副作用 | 使用默认 `pipelines.default`；自定义时把 `OptimisticLockSavePipe` 放在 `RecordHistoryPipe` / `DispatchAfterEventsPipe` **之前** |
| 成功响应剔除内部策略字段 | 依赖这些字段的前端/运维接口缺字段 | 从 `response.sensitive_fields` 移除对应键，或 `reveal_sensitive_fields = true` |
| `sla.enabled` 真正生效 | 开关为 `false` 时 SLA 不再执行任何动作 | 设为 `true`，或给定时任务加 `--force` |
| `rollback` / `delegate` / `cancelTasks` 需授权 | 原先「无权限即可回退」的流程失败 | 在权限实现中放行 `__rollback__` / `__delegate__` / `__cancel_tasks__` |

### 无需改造即受益

- 系统触发标记 `auto_sla` 与 `user_id` 不可由请求体伪造；
- 完成审批任务必须是任务分配人，转交必须是当前处理人；
- 事件驱动路径失败时任务行恢复 `pending`（不再出现「任务被吃掉」）；
- `assignees` 去重 + 基数上限、`policy` 枚举校验；
- 历史分页 `limit` 服务端夹取（1–200），一次请求拉不回全部历史；
- 业务异常的诊断信息只进日志，不回显给调用方。

### 建议的灰发顺序

1. 先只开启路由开关并挂上认证，观察一个业务周期；
2. 绑定权限实现与主体解析器（否则会全拒）；
3. 确认守卫白名单与响应裁剪对前端无影响；
4. 按需绑定归属契约、开启 SLA 自动动作。

---

## 依赖

| 依赖 | 版本要求 | 说明 |
|------|---------|------|
| PHP | >= 8.1 | 核心语言 |
| topthink/framework | ^6.0 \|\| ^8.0 | ThinkPHP 框架 |
| wise-support | 最新 | 扩展点、迁移基础设施 |
| [可选] wiseadmin/wise-mcp | 最新 | MCP 工具集成 |

---

## 许可证

Apache-2.0 License
