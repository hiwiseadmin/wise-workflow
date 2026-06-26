# Wise-Workflow 工作流引擎

> 基于 ThinkPHP 6/8 的轻量级工作流引擎，深度集成 WiseAdmin 框架的 RBAC、插件系统和事件系统  
> 设计思想参考 [Symfony Workflow](https://symfony.com/doc/current/components/workflow.html)，专为 WiseAdmin 生态定制  
> 版本：v2.0 | PHP >= 8.1 | ThinkPHP >= 6.0

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn) 
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--workflow-orange)](https://packagist.org/packages/wiseadmin/wise-workflow)

---

## 目录

- [概述](#概述)
- [核心概念](#核心概念)
- [架构设计](#架构设计)
- [安装与配置](#安装与配置)
- [快速上手](#快速上手)
- [API 参考](#api-参考)
  - [定义工作流](#定义工作流)
  - [注册工作流](#注册工作流)
  - [引擎操作](#引擎操作)
  - [组合 Facade](#组合-facade)
  - [权限守卫](#权限守卫)
  - [事件系统](#事件系统)
  - [历史记录](#历史记录)
- [数据库表结构](#数据库表结构)
- [插件集成指南](#插件集成指南)
- [WorkflowDemo 演示插件](#workflowdemo-演示插件)
- [与 WiseAdmin 框架集成](#与-wiseadmin-框架集成)
- [CLI 命令](#cli-命令)
- [进阶用法](#进阶用法)
- [常见问题](#常见问题)
- [开发与测试](#开发与测试)

---

## 概述

**Wise-Workflow** 是 WiseAdmin 生态的工作流引擎组件，采用**状态机模型（Place-Transition）**，可为任意业务对象（如订单、工单、审批单等）添加状态流转能力。它提供了完整的工作流定义、状态转换、权限检查、事件触发和历史记录等功能。

### 设计理念

| 设计原则 | 说明 |
|---------|------|
| **轻量级** | 只实现 80% 项目需要的功能，避免功能冗余 |
| **深度集成** | 与 WiseAdmin 的 RBAC、插件系统、事件系统、数据库层无缝对接 |
| **易用性** | 插件开发者使用成本低，与 ThinkPHP 框架风格一致 |
| **可扩展性** | 支持自定义守卫、存储、事件监听 |

### 功能特性

- 基于 Place-Transition 模型的状态机工作流引擎
- 支持从数据库动态加载工作流定义，也支持代码静态注册
- 深度集成 WiseAdmin RBAC 权限系统，每个转换均可绑定权限标识
- 自动触发 `before_transition` / `after_transition` 生命周期事件
- 完整的转换历史记录，包含操作人、操作IP、上下文数据
- 支持 Guard 守卫机制（字符串权限 / 回调函数）
- 通过 CLI 命令 + 标准迁移管理数据库表，完美支持版本升级和数据初始化
- 提供 Facade 门面简化调用，一行代码完成状态转换
- 支持插件工作流自动发现与注册
- 支持自定义状态存储（默认数据库，可扩展为 Redis 等）

---

## 核心概念

| 概念 | 英文 | 类 | 说明 |
|------|------|-----|------|
| **工作流** | Workflow | `wise\workflow\Workflow` | 工作流定义，包含名称、所有状态、所有转换规则、初始状态、元数据 |
| **状态节点** | Place | `wise\workflow\Place` | 工作流中的一个状态，如 "draft"（草稿）、"reviewing"（审核中） |
| **转换规则** | Transition | `wise\workflow\Transition` | 状态间的转换路径，定义从哪些状态（from）可以转换到哪些状态（to） |
| **标记** | Marking | `array` | 当前对象所处的工作流状态集合（如 `['draft' => 1]`） |
| **引擎** | WorkflowEngine | `wise\workflow\WorkflowEngine` | 核心执行引擎，负责 `apply()`、`can()`、`init()` 等操作 |
| **注册表** | WorkflowRegistry | `wise\workflow\WorkflowRegistry` | 全局工作流管理中心，支持静态注册和数据库动态加载 |
| **状态存储** | MarkingStore | `wise\workflow\MarkingStoreInterface` | 持久化存储工作流状态的接口（默认实现：数据库） |
| **守卫** | Guard | `wise\workflow\GuardListener` | 权限检查机制，确保只有授权用户才能执行特定转换 |

### 状态转换示意图

```
                     ┌──────────┐
         ┌── submit ─│ 已提交   │── assign ──┐
         │           │submitted │            │
  ┌──────┴─┐        └─────┬────┘       ┌────▼────┐
  │  草稿   │              │ cancel     │ 审核中   │
  │ draft  │              │            │reviewing │
  └────▲───┘              │            └──┬───┬──┘
       │                  │         reject│   │approve
       │   resubmit  ┌────▼────┐    ┌─────▼┐ ┌▼──────┐
       └─────────────│ 已拒绝   │    │已拒绝 │ │ 已通过 │
                     │rejected │    │rejected│ │approved│
                     └─────────┘    └───────┘ └───────┘
```

---

## 架构设计

### 分层结构

Wise-Workflow 采用**双层架构**设计：

```
┌─────────────────────────────────────────────────┐
│              应用层（App Layer）                    │
│  app/common/workflow/                            │
│  ├── Workflow.php         增强版工作流定义         │
│  ├── WorkflowEngine.php   增强版引擎（权限+事件）   │
│  ├── WorkflowRegistry.php 增强版注册表（懒加载DB） │
│  ├── GuardListener.php    权限守卫监听器          │
│  └── DatabaseMarkingStore 数据库状态存储          │
├─────────────────────────────────────────────────┤
│              核心层（Vendor Layer）                 │
│  vendor/wiseadmin/wise-workflow/src/             │
│  ├── Workflow.php         核心工作流定义          │
│  ├── WorkflowEngine.php   核心引擎               │
│  ├── WorkflowRegistry.php 核心注册表             │
│  ├── Place.php            状态节点               │
│  ├── Transition.php       转换规则               │
│  ├── Service.php          ServiceProvider        │
│  └── DatabaseMarkingStore 数据库状态存储          │
└─────────────────────────────────────────────────┘
```

### 目录结构

```
vendor/wiseadmin/wise-workflow/
├── composer.json                           # 包定义（PSR-4命名空间: wise\workflow\）
├── src/
│   ├── Workflow.php                        # 工作流定义类
│   ├── WorkflowEngine.php                  # 工作流核心引擎
│   ├── WorkflowRegistry.php                # 工作流注册表（静态+数据库）
│   ├── Service.php                          # ServiceProvider（容器绑定+命令注册）
│   ├── Place.php                            # 状态节点类
│   ├── Transition.php                      # 转换规则类
│   ├── MarkingStoreInterface.php           # 状态存储接口
│   ├── DatabaseMarkingStore.php            # 数据库存储实现
│   ├── GuardListener.php                   # 权限守卫监听器
│   ├── command/
│   │   └── Publish.php                     # CLI 命令（wise:workflow:publish）
│   ├── exception/
│   │   └── WorkflowException.php           # 工作流异常
│   ├── facade/
│   │   ├── Workflow.php                    # WorkflowEngine 的 Facade
│   │   └── WorkflowRegistry.php            # WorkflowRegistry 的 Facade
│   ├── model/
│   │   ├── WorkflowDefinition.php          # 工作流定义模型
│   │   ├── WorkflowMarking.php             # 状态标记模型
│   │   └── WorkflowHistory.php             # 历史记录模型
│   └── migrations/
│       ├── 20240101000001_create_workflow_definition_table.php
│       ├── 20240101000002_create_workflow_marking_table.php
│       └── 20240101000003_create_workflow_history_table.php
```

---

## 安装与配置

### 前置条件

- PHP >= 7.4
- ThinkPHP >= 6.0
- ThinkORM >= 3.0
- MySQL 5.7+（或兼容数据库）
- [可选] WiseAdmin 框架 >= 1.0.0

### 通过 Composer 安装

```bash
composer require wiseadmin/wise-workflow
```

### 数据库表创建

Wise-Workflow 使用 **标准 ThinkPHP 迁移** 管理数据库表，保证升级时表结构和初始化数据的一致性与可追溯性。

创建数据库表分为两步：

```bash
# 第一步：复制迁移文件到框架的 database/migrations/ 目录
php think wise:workflow:publish

# 第二步：执行迁移创建数据库表
php think migrate:run
```

> 提示：`wise:workflow:publish` 命令会自动将包内的迁移文件复制到 `database/migrations/` 目录。如果目标文件已存在会自动跳过。

### ServiceProvider 自动注册（最小绑定）

Wise-Workflow 通过 Composer 自动发现机制加载 `Service` 类，**仅做一项必要绑定**：

| 绑定 | 说明 |
|------|------|
| `MarkingStoreInterface` → `DatabaseMarkingStore` | 状态存储接口实现 |

其余类（`WorkflowEngine`、`WorkflowRegistry`、`Place`、`Transition` 等）由用户**主动 `use` 导入后直接使用**，保持依赖关系清晰可见：

```php
use wise\workflow\WorkflowEngine;
use wise\workflow\DatabaseMarkingStore;

$engine = new WorkflowEngine(new DatabaseMarkingStore());
```

安装后运行 `composer dump-autoload` 使 Service 自动发现生效即可。

---

## 快速上手

### 步骤一：定义工作流

在插件或应用代码中定义一个工作流：

```php
use wise\workflow\Workflow;
use wise\workflow\Place;
use wise\workflow\Transition;

// 创建一个订单审批工作流
$workflow = new Workflow('order_approval');

// 添加状态节点（Place）
$workflow->addPlace(new Place('draft'));       // 草稿
$workflow->addPlace(new Place('submitted'));   // 已提交
$workflow->addPlace(new Place('reviewing'));   // 审核中
$workflow->addPlace(new Place('approved'));    // 已通过
$workflow->addPlace(new Place('rejected'));    // 已拒绝

// 设置初始状态
$workflow->setMetadata(['initial_place' => 'draft']);

// 添加转换规则（Transition）
// 格式：new Transition(name, from, to)
$workflow->addTransition(new Transition('submit', 'draft', 'submitted'));
$workflow->addTransition(new Transition('assign', 'submitted', 'reviewing'));
$workflow->addTransition(new Transition('approve', 'reviewing', 'approved'));
$workflow->addTransition(new Transition('reject', 'reviewing', 'rejected'));
$workflow->addTransition(new Transition('resubmit', 'rejected', 'draft'));
```

### 步骤二：注册工作流

将定义好的工作流注册到全局注册表：

```php
use wise\workflow\WorkflowRegistry;

WorkflowRegistry::add($workflow);
```

### 步骤三：使用工作流引擎

```php
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\DatabaseMarkingStore;

// 创建引擎实例
$store = new DatabaseMarkingStore();
$engine = new WorkflowEngine($store);

// 获取工作流
$workflow = WorkflowRegistry::get('order_approval');

// 1. 初始化工作流状态（将订单设为初始状态）
$engine->init($workflow, 'order:1001');

// 2. 检查是否可以提交
if ($engine->can($workflow, 'order:1001', 'submit')) {
    // 3. 执行提交转换
    $engine->apply($workflow, 'order:1001', 'submit', [
        'operator' => 'admin',
        'remark' => '提交审批',
    ]);
    echo "提交成功！\n";
}

// 4. 获取当前状态
$marking = $engine->getMarking('order:1001');
print_r($marking); // 输出：['submitted' => 1]

// 5. 获取可用的转换
$enabled = $engine->getEnabledTransitions($workflow, 'order:1001');
foreach ($enabled as $transition) {
    echo $transition->getName() . "\n"; // 输出：assign
}
```

### 三步走总结

```
定义工作流（定义状态+转换规则） → 注册工作流（添加到 Register） → 使用引擎（操作状态流转）
```

---

## API 参考

### 定义工作流

#### Place 类（状态节点）

```php
namespace wise\workflow;

class Place
{
    /**
     * @param string $name 状态名称（唯一标识）
     */
    public function __construct(string $name);

    public function getName(): string;
}
```

**示例：**

```php
// 基本用法
$place = new Place('draft');

// 批量添加状态
$workflow->addPlace(new Place('draft'));
$workflow->addPlace(new Place('submitted'));
$workflow->addPlace(new Place('approved'));
```

#### Transition 类（转换规则）

```php
namespace wise\workflow;

class Transition
{
    /**
     * @param string $name 转换名称（唯一标识）
     * @param string|array $from 源状态（单个或多个）
     * @param string|array $to 目标状态（单个或多个）
     * @param callable|null $guard 守卫条件回调（可选）
     */
    public function __construct(string $name, $from, $to, ?callable $guard = null);

    public function getName(): string;
    public function getFrom(): array;
    public function getTo(): array;
    public function getGuard(): ?callable;
}
```

**示例：**

```php
// 基本转换：从 draft 到 submitted
$transition = new Transition('submit', 'draft', 'submitted');

// 多源状态：draft 或 rejected 都可以提交
$transition = new Transition('submit', ['draft', 'rejected'], 'submitted');

// 带守卫条件：只有金额小于 1000 的订单才能自动通过
$transition = new Transition('auto_approve', 'reviewing', 'approved', function ($marking, $context) {
    return $context['amount'] < 1000;
});
```

#### Workflow 类（工作流定义）

```php
namespace wise\workflow;

class Workflow
{
    /**
     * @param string $name 工作流名称（全局唯一）
     * @param array $places Place 对象数组
     * @param array $transitions Transition 对象数组
     * @param string|null $initialPlace 初始状态
     */
    public function __construct(string $name, array $places = [], array $transitions = [], $initialPlace = null);

    public function addPlace(Place $place): void;
    public function addTransition(Transition $transition): void;
    public function setMetadata(array $metadata): void;
    public function getName(): string;
    public function getPlaces(): array;
    public function getTransitions(): array;
    public function getInitialPlace(): ?string;
    public function getMetadata(): array;
}
```

---

### 注册工作流

#### WorkflowRegistry 类

```php
namespace wise\workflow;

class WorkflowRegistry
{
    /** 注册一个工作流 */
    public static function add(Workflow $workflow): void;

    /** 获取指定名称的工作流 */
    public static function get(string $name): Workflow;

    /** 获取所有已注册的工作流 */
    public static function all(): array;

    /** 判断工作流是否存在 */
    public static function has(string $name): bool;

    /** 清除所有已注册的工作流 */
    public static function clear(): void;

    /** 从数据库加载所有启用的工作流 */
    public static function loadFromDatabase(): void;
}
```

**两种注册方式：**

```php
// 方式一：代码静态注册
$workflow = new Workflow('order_approval');
// ... 添加 places 和 transitions
WorkflowRegistry::add($workflow);

// 方式二：数据库动态注册
// 先在 workflow_definition 表中插入配置，然后：
WorkflowRegistry::loadFromDatabase();
```

---

### 引擎操作

#### WorkflowEngine 类

```php
namespace wise\workflow;

class WorkflowEngine
{
    /**
     * @param MarkingStoreInterface $markingStore 状态存储（必须）
     * @param mixed $eventService 事件服务（可选）
     * @param mixed $permissionService 权限服务（可选）
     * @param mixed $historyModel 历史记录模型（可选）
     */
    public function __construct(
        MarkingStoreInterface $markingStore,
        $eventService = null,
        $permissionService = null,
        $historyModel = null
    );
}
```

#### 核心方法说明

| 方法 | 说明 | 返回值 |
|------|------|--------|
| `init(Workflow $workflow, string $subject, array $context)` | 初始化工作流（设置到初始状态） | `void` |
| `apply(Workflow $workflow, string $subject, string $transitionName, array $context)` | 执行一次状态转换 | `array` 转换后的新状态 |
| `can(Workflow $workflow, string $subject, string $transitionName, array $context)` | 检查是否可执行转换 | `bool` |
| `getEnabledTransitions(Workflow $workflow, string $subject)` | 获取当前可用的转换列表 | `Transition[]` |
| `getMarking(string $subject)` | 获取当前状态 | `array` |
| `setMarking(string $subject, array $marking, array $context)` | 设置状态 | `void` |
| `getHistory(string $subject, int $limit = 50)` | 获取转换历史 | `array` |

#### apply() 完整执行流程

```
apply(workflow, subject, transitionName, context)
    │
    ├── 1. 读取当前状态（从 markingStore）
    ├── 2. 查找转换规则（findTransition）
    ├── 3. 检查权限（checkPermission → PermissionService）
    ├── 4. 检查守卫条件（transition.getGuard）
    ├── 5. 执行状态转换（移除 from places，添加 to places）
    ├── 6. 保存新状态（markingStore.setMarking）
    ├── 7. 记录历史（markingStore.recordHistory）
    └── 8. 触发事件（dispatchEvent → workflow.{name}.{transition}）
```

#### subject 参数说明

`subject` 是流程实例的**唯一标识**，格式为 `{类型}:{ID}`：

```php
// 订单
$subject = 'order:1001';

// 工单
$subject = 'ticket:W-2024-001';

// 也可以自定义格式
$subject = 'document_approval_42';
```

---

### 组合 Facade

在 WiseAdmin 应用层中，提供了一个增强版的组合 Facade，**一行代码完成常用操作**：

```php
use app\common\facade\Workflow;
```

| 方法 | 说明 | 示例 |
|------|------|------|
| `init($workflowName, $subject)` | 初始化工作流状态 | `Workflow::init('order_approval', $order)` |
| `apply($workflowName, $subject, $transition, $context)` | 执行状态转换 | `Workflow::apply('order_approval', $order, 'submit')` |
| `can($workflowName, $subject, $transition)` | 检查是否可执行 | `Workflow::can('order_approval', $order, 'submit')` |
| `transitions($workflowName, $subject)` | 获取可用转换列表 | `Workflow::transitions('order_approval', $order)` |
| `marking($workflowName, $subject)` | 获取当前状态 | `Workflow::marking('order_approval', $order)` |
| `is($workflowName, $subject, $place)` | 判断是否处于某状态 | `Workflow::is('order_approval', $order, 'draft')` |
| `exists($workflowName)` | 判断工作流是否存在 | `Workflow::exists('order_approval')` |
| `register($workflow)` | 注册工作流 | `Workflow::register($workflow)` |
| `history($workflowName, $subject, $limit)` | 获取历史记录 | `Workflow::history('order_approval', $order, 10)` |

**完整示例：**

```php
use app\common\facade\Workflow;

// 创建订单后初始化工作流
$order = Order::create([...]);
Workflow::init('order_approval', $order);

// 在控制器中处理审核
public function approve($orderId)
{
    $order = Order::find($orderId);

    // 检查权限（自动通过 GuardListener 处理）
    if (!Workflow::can('order_approval', $order, 'approve')) {
        return $this->error('无权执行此操作或当前状态不允许');
    }

    // 执行转换
    Workflow::apply('order_approval', $order, 'approve', [
        'operator' => session('admin_id'),
        'remark' => '审核通过',
    ]);

    // 获取当前可执行操作
    $transitions = Workflow::transitions('order_approval', $order);

    // 判断状态
    $isApproved = Workflow::is('order_approval', $order, 'approved');

    return $this->success('审核完成', compact('transitions', 'isApproved'));
}
```

---

### 权限守卫

Wise-Workflow 通过 **GuardListener** 实现基于 RBAC 的权限控制。

#### GuardListener 类

```php
namespace wise\workflow;

class GuardListener
{
    /**
     * @param mixed $permissionService 权限服务（如 PermissionService）
     * @param bool $enabled 是否启用权限检查
     */
    public function __construct($permissionService = null, bool $enabled = true);
}
```

#### 工作原理

```
用户操作 → WorkflowEngine::apply()
    ↓
GuardListener::onBeforeTransition()
    ↓
检查 Transition 是否配置了 guard 权限标识
    ↓
调用 PermissionService::check(guardString)
    ↓
有权限 → 继续执行转换
无权限 → 抛出异常阻止操作
```

#### 使用方式

##### 方式一：在 Transition 上绑定权限字符串（推荐）

```php
// 定义转换时指定权限标识
$transition = new Transition(
    'approve',           // 转换名称
    'reviewing',         // 源状态
    'approved',          // 目标状态
    function ($marking, $context) use ($permissionService) {
        // 此处传入权限检查回调
        return $permissionService->check('order.approve');
    }
);
```

##### 方式二：在上下文 Context 中传递权限

```php
Workflow::apply('order_approval', $order, 'approve', [
    'user_id' => session('admin_id'),           // 操作人ID
    'guard' => 'workflow.order.approve',        // 需要检查的权限标识
    'remark' => '审核通过',
]);
```

#### 权限命名规范

| 模式 | 说明 | 示例 |
|------|------|------|
| `{module}.{action}` | 模块级权限 | `order.submit`, `order.approve` |
| `workflow.{name}.{transition}` | 工作流转换权限 | `workflow.order_approval.approve` |
| `workflow.{name}.*` | 工作流全部权限 | `workflow.order_approval.*` |
| `workflow.*` | 所有工作流权限 | `workflow.*` |

---

### 事件系统

工作流引擎在每次状态转换前后会触发事件，支持开发者注册监听器实现自定义逻辑。

#### 事件列表

| 事件名称 | 触发时机 | 参数 | 用途 |
|---------|---------|------|------|
| `workflow.before_transition` | 转换执行前 | `{workflow, transition, subject, context}` | 权限检查、前置条件验证、阻止非法操作 |
| `workflow.after_transition` | 转换执行后 | `{workflow, transition, subject, context}` | 记录日志、发送通知、更新关联数据 |
| `workflow.{name}.{transition}` | 特定转换执行后 | `{workflow, transition, subject, context}` | 特定场景的自定义逻辑 |

#### 使用示例

```php
use think\facade\Event;

// 监听转换后事件 - 发送通知
Event::listen('workflow.after_transition', function ($params) {
    $workflow   = $params['workflow'];
    $transition = $params['transition'];
    $subject    = $params['subject'];
    $context    = $params['context'];

    // 只处理特定工作流
    if ($workflow->getName() !== 'order_approval') {
        return;
    }

    switch ($transition->getName()) {
        case 'submit':
            // 订单提交后通知审核员
            Notifier::send('reviewer', "新订单 {$subject} 等待审核");
            break;

        case 'approve':
            // 审核通过后更新订单状态
            Order::where('order_no', $subject)
                ->update(['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')]);
            break;

        case 'reject':
            // 审核拒绝后通知提交人
            Notifier::send($context['submitter_id'], "订单 {$subject} 审核被拒绝");
            break;
    }
});

// 监听特定转换事件
Event::listen('workflow.order_approval.approve', function ($params) {
    // 只针对 order_approval 工作流的 approve 转换
    Log::record("订单 {$params['subject']} 已被审核通过", 'workflow');
});
```

#### 阻止非法转换

```php
// 在 before_transition 事件中进行业务校验
Event::listen('workflow.before_transition', function ($params) {
    $subject  = $params['subject'];
    $context  = $params['context'];

    // 业务校验：审核通过前检查金额
    if ($params['transition']->getName() === 'approve') {
        $order = Order::find($subject);
        if ($order->amount > 100000 && empty($context['manager_approved'])) {
            throw new \Exception('大额订单需要经理审批');
        }
    }
});
```

---

### 历史记录

每一次状态转换都会自动记录到 `workflow_history` 表中。

#### 查询历史

```php
// 通过引擎
$history = $engine->getHistory('order:1001', 20);

// 通过 Facade
$history = Workflow::history('order_approval', $order, 20);

// 通过模型直接查询
$history = \wise\workflow\model\WorkflowHistory::where('subject', 'order:1001')
    ->order('id', 'desc')
    ->select();
```

#### 历史记录数据结构

```php
[
    'id'              => 42,                    // 记录ID
    'subject'         => 'order:1001',          // 流程实例标识
    'transition_name' => 'approve',             // 转换名称
    'from_place'      => 'reviewing',           // 源状态
    'to_place'        => 'approved',            // 目标状态
    'context'         => '{"remark":"审核通过"}', // 上下文（JSON）
    'user_id'         => 1,                     // 操作用户ID
    'ip'              => '192.168.1.100',       // 操作IP
    'user_agent'      => 'Mozilla/5.0...',      // User-Agent
    'create_time'      => '2024-01-15 14:30:00', // 创建时间
]
```

---

## 数据库表结构

Wise-Workflow 使用三张核心表：

### workflow_definition（工作流定义表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT(11) PK | 主键ID |
| `name` | VARCHAR(50) UNIQUE | 工作流名称（唯一标识） |
| `title` | VARCHAR(100) | 工作流显示名称 |
| `description` | VARCHAR(255) | 工作流描述 |
| `places` | TEXT（JSON） | 状态节点列表，如 `["draft","submitted","approved"]` |
| `transitions` | TEXT（JSON） | 转换规则列表，如 `[{"name":"submit","from":["draft"],"to":["submitted"]}]` |
| `initial_place` | VARCHAR(50) | 初始状态名称 |
| `status` | TINYINT(1) | 1=启用, 0=禁用 |
| `create_time` | DATETIME | 创建时间 |
| `update_time` | DATETIME | 更新时间 |

### workflow_marking（状态标记表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT(11) PK | 主键ID |
| `subject` | VARCHAR(100) UNIQUE | 流程实例标识（如 `order:1001`） |
| `marking` | TEXT（JSON） | 当前状态标记，如 `{"submitted":1}` |
| `create_time` | DATETIME | 创建时间 |
| `update_time` | DATETIME | 更新时间 |

### workflow_history（历史记录表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT(11) PK | 主键ID |
| `subject` | VARCHAR(100) INDEXED | 流程实例标识 |
| `transition_name` | VARCHAR(50) INDEXED | 转换名称 |
| `from_place` | VARCHAR(50) | 源状态 |
| `to_place` | VARCHAR(50) | 目标状态 |
| `context` | TEXT（JSON） | 上下文数据 |
| `user_id` | INT(11) | 操作用户ID |
| `ip` | VARCHAR(45) | 操作IP |
| `user_agent` | VARCHAR(255) | 浏览器标识 |
| `create_time` | DATETIME INDEXED | 创建时间 |

---

## 插件集成指南

在 WiseAdmin 插件中使用工作流引擎只需三步：

### 第一步：在 Plugin.php 中定义工作流

```php
<?php
namespace plugin\myplugin;

use wise\workflow\Workflow;
use wise\workflow\Place;
use wise\workflow\Transition;

class Plugin
{
    /**
     * 获取工作流定义
     *
     * @return Workflow[]
     */
    public static function getWorkflowDefinitions(): array
    {
        $workflow = new Workflow('my_approval');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('submitted'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addPlace(new Place('rejected'));

        $workflow->addTransition(new Transition('submit', 'draft', 'submitted'));
        $workflow->addTransition(new Transition('approve', 'submitted', 'approved'));
        $workflow->addTransition(new Transition('reject', 'submitted', 'rejected'));

        $workflow->setMetadata([
            'title'       => '我的审批流程',
            'description' => '自定义审批工作流',
            'initial_place' => 'draft',
        ]);

        return [$workflow];
    }
}
```

### 第二步：在 BootServiceProvider.php 中注册

```php
<?php
namespace plugin\myplugin;

use app\common\facade\Workflow;

class BootServiceProvider
{
    public function boot(): void
    {
        // 注册工作流
        $definitions = Plugin::getWorkflowDefinitions();
        foreach ($definitions as $workflow) {
            Workflow::register($workflow);
        }

        // 监听工作流事件
        \think\facade\Event::listen('workflow.after_transition', function ($params) {
            if ($params['workflow']->getName() === 'my_approval') {
                // 自定义逻辑：发送通知、更新数据等
            }
        });
    }
}
```

### 第三步：在控制器中使用

```php
<?php
namespace plugin\myplugin\controller;

use app\common\controller\BaseController;
use app\common\facade\Workflow;

class ApprovalController extends BaseController
{
    /**
     * 提交审批
     */
    public function submit()
    {
        $id = $this->request->param('id');
        $record = MyModel::find($id);

        if (!$record) {
            return $this->error('记录不存在');
        }

        try {
            // 检查权限
            if (!Workflow::can('my_approval', $record, 'submit')) {
                return $this->error('当前状态不允许提交');
            }

            // 执行转换
            Workflow::apply('my_approval', $record, 'submit', [
                'user_id' => session('admin_id'),
            ]);

            return $this->success('提交成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取审批详情（含可用操作）
     */
    public function detail()
    {
        $id = $this->request->param('id');
        $record = MyModel::find($id);

        // 获取当前状态和可用操作
        $marking = Workflow::marking('my_approval', $record);
        $transitions = Workflow::transitions('my_approval', $record);
        $history = Workflow::history('my_approval', $record, 10);

        $availableActions = [];
        foreach ($transitions as $trans) {
            $availableActions[] = [
                'name'  => $trans->getName(),
                'label' => $trans->getName(), // 可从 metadata 获取显示名称
                'can'   => Workflow::can('my_approval', $record, $trans->getName()),
            ];
        }

        return $this->success('获取成功', [
            'record'           => $record,
            'current_place'    => $marking,
            'available_actions' => $availableActions,
            'history'          => $history,
        ]);
    }
}
```

### 在 manifest.json 中添加菜单和权限

```json
{
    "name": "myplugin",
    "title": "我的插件",
    "menus": [
        {
            "name": "审批管理",
            "icon": "fa-check-square",
            "children": [
                {
                    "name": "审批列表",
                    "url": "myplugin/approval/index"
                }
            ]
        }
    ],
    "permissions": [
        {
            "name": "workflow.my_approval.submit",
            "title": "提交审批"
        },
        {
            "name": "workflow.my_approval.approve",
            "title": "审核通过"
        },
        {
            "name": "workflow.my_approval.reject",
            "title": "审核拒绝"
        }
    ]
}
```

---

## WorkflowDemo 演示插件

WiseAdmin 提供了一个完整的 **WorkflowDemo** 演示插件，位于 `plugins/workflowdemo/`，展示订单审批工作流的完整实现。

### 演示工作流

```
草稿(draft) ──提交审核(submit)──→ 已提交(submitted)
已提交(submitted) ──分配审核员(assign)──→ 审核中(reviewing)
审核中(reviewing) ──审核通过(approve)──→ 已通过(approved)
审核中(reviewing) ──审核拒绝(reject)──→ 已拒绝(rejected)
已拒绝(rejected) ──重新提交(resubmit)──→ 草稿(draft)
草稿(draft)/已提交(submitted) ──取消(cancel)──→ 已取消(cancelled)
```

### 权限标识

| 权限标识 | 对应操作 |
|---------|---------|
| `workflow.order.submit` | 提交审核 |
| `workflow.order.assign` | 分配审核员 |
| `workflow.order.approve` | 审核通过 |
| `workflow.order.reject` | 审核拒绝 |
| `workflow.order.resubmit` | 重新提交 |
| `workflow.order.cancel` | 取消订单 |

### 目录结构

```
plugins/workflowdemo/
├── Plugin.php                          # 插件主类（工作流定义）
├── manifest.json                       # 插件清单（菜单+权限定义）
├── BootServiceProvider.php             # 启动服务提供者（注册工作流+事件）
├── controller/
│   ├── Base.php                        # 控制器基类
│   └── Order.php                       # 订单控制器（工作流操作）
├── model/
│   └── WorkflowOrder.php               # 订单模型
├── route/
│   └── plugin.php                       # 后端路由
├── database/migrations/
│   └── 20260606000001_create_workflow_orders_table.php
└── view/order/
    ├── index.html                       # 订单列表页
    ├── form.html                        # 订单创建/编辑页
    └── detail.html                      # 订单详情页（工作流操作按钮）
```

### 运行演示

```bash
# 1. 运行数据库迁移
php think migrate:run --plugin workflowdemo

# 2. 在后台管理 → 插件管理 中启用 workflowdemo 插件

# 3. 访问：后台 → 工作流演示 → 订单管理
```

---

## 与 WiseAdmin 框架集成

### 容器绑定（自动完成）

`Service` 类通过 Composer 自动发现加载，仅绑定 `MarkingStoreInterface → DatabaseMarkingStore`。

使用时直接导入对应类即可：

---

## CLI 命令

```bash
# 工作流初始化：发布配置文件和迁移文件到框架对应目录
php think wise:workflow:publish

# 然后执行 ThinkPHP 系统迁移命令创建数据库表
php think migrate:run
```

`wise:workflow:publish` 命令会完成以下操作：
1. 扫描包内 `src/migrations/` 目录下的迁移文件
2. 逐个复制到 `database/migrations/` 目录
3. 如果目标文件已存在，自动跳过
4. 输出完成提示，引导用户执行系统迁移

---

## 进阶用法

### 自定义状态存储

默认使用数据库存储（`workflow_marking` 表），可以扩展到 Redis 等：

```php
use wise\workflow\MarkingStoreInterface;
use wise\workflow\Workflow;

class RedisMarkingStore implements MarkingStoreInterface
{
    protected $redis;

    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    public function getMarking(string $subject): array
    {
        $data = $this->redis->get('workflow:marking:' . $subject);
        return $data ? json_decode($data, true) : [];
    }

    public function setMarking(string $subject, array $marking, array $context = []): void
    {
        $this->redis->set('workflow:marking:' . $subject, json_encode($marking));
    }

    public function recordHistory(string $subject, string $transitionName, string $fromPlace, string $toPlace, array $context = []): void
    {
        // 历史记录仍可写入数据库或 Redis List
    }
}

// 在 ServiceProvider 中替换绑定
$this->app->bind(MarkingStoreInterface::class, function () {
    return new RedisMarkingStore(redis());
});
```

### 自定义守卫条件

高级场景下可使用回调守卫：

```php
// 条件守卫：根据业务数据判断
$transition = new Transition(
    'auto_approve',
    'reviewing',
    'approved',
    function ($marking, $context) {
        // 金额小于 1000 的订单自动通过
        if (isset($context['amount']) && $context['amount'] < 1000) {
            return true;
        }
        // 否则需要手动审批权限
        return PermissionService::check('order.approve');
    }
);
```

### 工作流定义存储到数据库

将工作流定义存储到 `workflow_definition` 表，实现动态管理：

```php
// 方式一：直接插入数据库
\think\facade\Db::name('workflow_definition')->insert([
    'name'          => 'order_approval',
    'title'         => '订单审批流程',
    'description'   => '标准订单审批工作流',
    'places'        => json_encode(['draft', 'submitted', 'reviewing', 'approved', 'rejected']),
    'transitions'   => json_encode([
        ['name' => 'submit',  'from' => ['draft'],     'to' => ['submitted']],
        ['name' => 'assign',  'from' => ['submitted'],  'to' => ['reviewing']],
        ['name' => 'approve', 'from' => ['reviewing'],  'to' => ['approved']],
        ['name' => 'reject',  'from' => ['reviewing'],  'to' => ['rejected']],
    ]),
    'initial_place' => 'draft',
    'status'        => 1,
    'create_time'   => date('Y-m-d H:i:s'),
    'update_time'   => date('Y-m-d H:i:s'),
]);

// 方式二：通过 Facade 注册（动态创建定义）
$workflow = (new Workflow('my_approval'))
    ->addPlace(new Place('draft'))
    ->addPlace(new Place('approved'))
    ->addTransition(new Transition('approve', 'draft', 'approved'));
$workflow->setMetadata([
    'title'       => '我的审批流',
    'description' => '测试',
    'initial_place' => 'draft',
]);
Workflow::register($workflow);
```

### 业务对象的状态冗余

建议在业务表中冗余存储当前状态字段，方便查询：

```php
// 监听 after_transition 事件同步更新业务表
Event::listen('workflow.after_transition', function ($params) {
    $subject = $params['subject'];
    $newPlaces = $params['workflow']->getPlaces(); // 结合 marking 获取当前状态

    // 更新业务表的状态字段
    $orderId = str_replace('order:', '', $subject);
    \think\facade\Db::name('orders')
        ->where('id', $orderId)
        ->update(['wf_status' => implode(',', array_keys($newPlaces))]);
});
```

---

## 常见问题

### Q: 如何判断当前工作流状态？

```php
// 使用 Facade
if (Workflow::is('order_approval', $order, 'draft')) {
    echo '当前为草稿状态';
}

// 使用引擎
$marking = $engine->getMarking('order:1001');
if (isset($marking['draft'])) {
    echo '当前为草稿状态';
}
```

### Q: 如何获取当前可执行的操作列表？

```php
$transitions = Workflow::transitions('order_approval', $order);
foreach ($transitions as $transition) {
    echo $transition->getName(); // submit, approve, reject...
}
```

### Q: 如何自定义权限检查逻辑？

修改 `Transition` 的 guard 回调即可：

```php
$transition = new Transition('approve', 'reviewing', 'approved', function ($marking, $context) {
    // 自定义权限逻辑
    return $context['user_role'] === 'manager' && $context['amount'] < 10000;
});
```

### Q: 一个业务对象可以绑定多个工作流吗？

可以。每个工作流通过 `name` 区分，绑定到不同的 `subject`，互不影响。同一个业务对象可以通过不同的 subject 标识绑定不同工作流：

```php
$engine->init($approvalWorkflow, 'order:1001');    // 审批工作流
$engine->init($paymentWorkflow, 'order:1001');      // 支付工作流（共享同一 subject）
```

### Q: 如何回滚到上一个状态？

工作流引擎本身不提供"回滚"功能。如果需要，可以定义一个反向的 Transition：

```php
// 从已通过回退到审核中
$workflow->addTransition(new Transition('rollback', 'approved', 'reviewing'));
```

### Q: 表前缀如何配置？

Wise-Workflow 使用 ThinkPHP 的数据库连接配置，自动适配项目中的表前缀设置（`ws_`）：

```php
// 在 database.php 中配置
'connections' => [
    'mysql' => [
        'prefix' => 'ws_',  // 表前缀，如 ws_workflow_marking
    ],
],
```

---

## 开发与测试

### 运行单元测试

```bash
cd vendor/wiseadmin/wise-workflow
composer install --dev
./vendor/bin/phpunit
```

### 依赖

| 依赖 | 版本要求 | 说明 |
|------|---------|------|
| PHP | >= 7.4 | 核心语言 |
| topthink/framework | >= 6.0 | ThinkPHP 6 框架 |
| topthink/think-orm | >= 3.0 | ThinkORM 数据库组件 |

### 开发依赖

| 依赖 | 版本 | 说明 |
|------|------|------|
| phpunit/phpunit | >= 9.0 | 单元测试 |
| mockery/mockery | >= 1.0 | Mock 框架 |

---

## 许可协议

MIT License

---

> 更多信息请参考 WiseAdmin 框架文档和 WorkflowDemo 演示插件。
