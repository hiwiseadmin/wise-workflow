# Wise-Workflow 测试套件总结

**运行结果：136 个测试全部通过 ✅ | 272 个断言 | 0 失败**

---

## 测试结构

```
tests/
├── phpunit.xml                  # PHPUnit 配置
├── bootstrap.php                # 测试引导（PSR-4 + Mockery + TP stub）
├── Unit/
│   ├── PlaceTest.php            (11 tests)  Place 状态节点
│   ├── TransitionTest.php       (15 tests)  Transition 转换规则
│   ├── WorkflowTest.php         (16 tests)  Workflow 工作流定义
│   ├── WorkflowRegistryTest.php (14 tests)  WorkflowRegistry 注册表
│   ├── WorkflowEngineTest.php   (29 tests)  WorkflowEngine 引擎核心
│   ├── GuardListenerTest.php    (14 tests)  GuardListener 权限守卫
│   └── WorkflowExceptionTest.php(9 tests)   WorkflowException 异常类
└── Integration/
    ├── WorkflowIntegrationTest.php (14 tests)  端到端集成场景
    └── DatabaseMarkingStoreTest.php (14 tests) DatabaseMarkingStore 数据存储
```

## 覆盖范围

| 类 | 方法 | 覆盖要点 |
|---|---|---|
| **Place** | __construct, getName, __toString | 标准名、空字符串、数字、中文、长字符串 |
| **Transition** | __construct, getName, getFrom, getTo, getGuard, setGuard | 单/多 from/to、guard 守卫、覆盖 guard、空数组 |
| **Workflow** | 构造, addPlace, addTransition, get/setMetadata | 空参/带参构造、状态管理、转换管理、元数据 |
| **WorkflowRegistry** | add, get, has, all, clear | 静态注册、查找、异常、清空、覆盖 |
| **WorkflowEngine** | can, apply, getEnabledTransitions, init, getMarking, setMarking, getHistory | 状态检查、转换执行、guard、权限/事件服务、多 from/to |
| **GuardListener** | __invoke, create | 无权限放行、有权限检查、缺少上下文回退、工厂方法 |
| **WorkflowException** | __construct, getData | 默认 data、带 data/code、继承 ValidateException |
| **DatabaseMarkingStore** | getMarking, setMarking, recordHistory | 新增/更新、无效 JSON、历史记录、Mock ThinkPHP Model |

## 集成场景覆盖

1. ✅ 简单线性审批（draft → submit → approve → approved）
2. ✅ 拒绝后重新提交（request_revision → revised → resubmit）
3. ✅ 带守卫的大额订单升级（escalate, amount > 10000）
4. ✅ 带守卫的金额不满足时阻止
5. ✅ 多主体并发独立状态
6. ✅ can() 检查 + getEnabledTransitions() 查询
7. ✅ 终态不可再转换
8. ✅ WorkflowRegistry + WorkflowEngine 集成
9. ✅ GuardListener + Transition guard 集成
10. ✅ 错误状态拒绝转换
11. ✅ 不存在转换的异常处理
12. ✅ 完整 init → apply 生命周期
13. ✅ 复杂分支工作流（分支 A/B → 合并 → 结束）
14. ✅ 混合守卫条件（多字段条件）

## 运行方式

```bash
# 在 wise-workflow 包目录下执行
cd src/packages/wiseadmin/wise-workflow
php vendor/bin/phpunit-9.phar --configuration tests/phpunit.xml

# 仅运行单元测试
php vendor/bin/phpunit-9.phar --configuration tests/phpunit.xml --testsuite "Wise-Workflow Unit Tests"
```
