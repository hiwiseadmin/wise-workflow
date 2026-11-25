<?php
/**
 * WiseWorkflow 配置文件
 * 
 * 通过 config('wise-workflow.xxx') 读取配置项
 */
return [
    // 数据库连接（空=默认）
    'connection'  => '',
    // 缓存配置
    'cache'       => [
        'enabled' => true,
        'ttl'     => 3600,
    ],
    // 通知配置
    'notification' => [
        'enabled'   => false,
        'channel'   => 'database',  // database / mail / sms / wechat
        'templates' => [
            'transition' => '工作流 [{workflow}] 状态由 [{from}] 变更为 [{to}]',
            'delegate'   => '工作流 [{workflow}] 已由 [{from_user}] 委托给您处理',
            'sla_alert'  => '工作流 [{workflow}] 在 [{place}] 状态已超时 [{duration}]',
        ],
    ],
    // SLA 配置
    'sla'          => [
        'enabled'         => false,
        'default_timeout' => 86400,     // 默认超时（秒），24小时
        'default_action'  => 'notify',   // 默认超时动作：notify/auto_reject/escalate
        'cron_expression' => '*/5 * * * *', // SLA 检查频率
    ],
    // 乐观锁配置
    'lock'         => [
        'enabled'      => true,
        'max_retries'  => 3,            // 最大重试次数
    ],
    // 版本管理默认策略：strict / migrate / legacy
    'version_policy' => 'strict',

    // ───────────────────── V2: Pipeline 管道配置 ─────────────────────

    // 管道工序列表（按执行顺序排列）
    // 支持三种场景管道：default / approval / simple
    'pipelines' => [
        // 默认管道（所有工作流共享，13 步标准流程）
        //
        // 安全审计阶段 1 / F-3：OptimisticLockSavePipe **必须排在
        // RecordHistoryPipe 与 DispatchAfterEventsPipe 之前**。
        // 该工序「先落库、再执行后续工序」，因此它之前的工序属于「准备/校验」，
        // 之后才是「副作用」（历史写入、事件触发）。乐观锁冲突会在落库时抛出，
        // 此时副作用尚未发生，Engine 的重试也不会重复执行副作用。
        // 若把它放到最后，副作用仍会先于落库提交，重试会重复写入历史与事件。
        'default' => [
            wise\workflow\pipes\FindTransitionPipe::class,
            wise\workflow\pipes\CheckPendingTasksPipe::class,
            wise\workflow\pipes\LoadMarkingPipe::class,
            wise\workflow\pipes\VerifyVersionPipe::class,
            wise\workflow\pipes\DispatchBeforeEventPipe::class,
            wise\workflow\pipes\CheckPermissionPipe::class,
            wise\workflow\pipes\EvaluateGuardsPipe::class,
            wise\workflow\pipes\ValidateFromPlacesPipe::class,
            // 二次确认：转换声明 confirm=true 时要求 context.confirmed === true。
            // 放在所有「合法性校验」之后、状态置换之前：状态/权限不对时优先报出真实原因。
            wise\workflow\pipes\CheckConfirmPipe::class,
            wise\workflow\pipes\TransitionStatePipe::class,
            // ↓ 落库必须在副作用工序之前（同上）
            wise\workflow\pipes\OptimisticLockSavePipe::class,
            wise\workflow\pipes\RecordHistoryPipe::class,
            wise\workflow\pipes\DispatchAfterEventsPipe::class,
        ],

        // 审批类工作流（保留，与 default 相同，预留 SLA 扩展点）
        'approval' => null,  // null = 继承 default

        // 精简管道（无并行审批检查、无事件、无权限——适合简单状态机）
        'simple' => [
            wise\workflow\pipes\FindTransitionPipe::class,
            wise\workflow\pipes\LoadMarkingPipe::class,
            wise\workflow\pipes\ValidateFromPlacesPipe::class,
            // 二次确认与 default 保持一致：拦截的是「元数据声明要确认」的转换，
            // 未声明时是零成本跳过，不应因用到精简管道而失效。
            wise\workflow\pipes\CheckConfirmPipe::class,
            wise\workflow\pipes\TransitionStatePipe::class,
            // ↓ 同上：落库在历史记录之前
            wise\workflow\pipes\OptimisticLockSavePipe::class,
            wise\workflow\pipes\RecordHistoryPipe::class,
        ],
    ],

    // 命名插槽（供插件注册自定义 Pipe）
    // 插槽名规则：{before|after}_{PipeBaseName}
    // 例：'after_VerifyVersion' = 在 VerifyVersionPipe 之后执行
    'pipe_slots' => [
        'before_LoadMarking'       => [],  // 状态加载前
        'after_VerifyVersion'      => [],  // 版本校验后
        'before_CheckPermission'   => [],  // 权限检查前
        'after_CheckPermission'    => [],  // 权限检查后
        'before_EvaluateGuards'    => [],  // 守卫检查前
        'after_EvaluateGuards'     => [],  // 守卫检查后
        'before_CheckConfirm'      => [],  // 二次确认前
        'after_CheckConfirm'       => [],  // 二次确认后
        'before_TransitionState'   => [],  // 状态转换前
        'after_TransitionState'    => [],  // 状态转换后
        'before_OptimisticLockSave'=> [],  // 保存前
        'after_OptimisticLockSave' => [],  // 保存后
        'before_RecordHistory'     => [],  // 历史记录前
        'after_DispatchAfterEvents'=> [],  // 事件触发后
    ],

    // ───────────────────── 权限服务装配策略（安全审计阶段 1） ─────────────────────
    // CheckPermissionPipe 从容器标识 'permission' 取权限服务；
    // 此前「取不到就放行（fail-open）」等于把权限工序变成空操作。
    'permission' => [
        // 未注入权限服务时是否放行。
        //   false（默认）= 拒绝（fail-closed）：没有权限系统就拒绝转换，而不是静默放行。
        //   true          = 放行：仅当你**明确决定不要权限系统**时设置，风险自负。
        'allow_all' => false,
    ],

    // ───────────────────── 守卫表达式求值策略（安全审计阶段 1） ─────────────────────
    // ── HTTP 响应字段裁剪（安全审计阶段 3 / E-2）─────────────────────────
    'response' => [
        // 命中这些键名的字段不会出现在任何成功响应中（递归匹配）。
        // 默认剔除内部策略字段（守卫表达式、SLA 配置）与操作者痕迹（IP/UA）。
        'sensitive_fields' => ['guard_expression', 'sla_config', 'ip', 'user_agent'],

        // true = 完全不裁剪（旧行为，不推荐）。
        // 如需「管理员可见、普通用户不可见」，请在自己的中间件中按请求覆写本配置。
        'reveal_sensitive_fields' => false,
    ],

    // ── 并行审批任务（安全审计阶段 3 / F-4）─────────────────────────────
    'task' => [
        // 单请求允许的审批人基数上限（去重后），超出返回 422
        'max_assignees' => 50,
    ],

    'expression' => [
        // 严格模式：表达式出现无法识别的标识符时直接报错。
        //   true （默认）—— 防止「漏写 context. 前缀导致条件恒为真」这类 fail-open；
        //                  存量表达式如有笔误会在启用后显式报错（这正是修复目标）。
        //   false         —— 未识别标识符按 false 处理（仍然 fail-closed，但不报错）。
        'strict' => true,

        // 守卫可读取的 context **顶层键白名单**（安全审计阶段 2 / B-3）。
        //   null  = 不限制（兼容旧行为，不推荐）
        //   数组  = 白名单：未列出的键在守卫中解析为 null（条件为假），并记录一条告警日志
        //
        // 默认只放行「包自身注入或消费」的键。业务若在自己的 guard_expression 中读取
        // 自定义 context 键（如 amount、department_id），**必须显式加入本列表**，
        // 否则该守卫会恒假（fail-closed）。升级后请检查日志中的白名单告警。
        'allowed_context_keys' => [
            'transition_name',
            'workflow_name',
            'definition_version',
            'auto_sla',
            'operator',
            'subject',
            'user_id',
            'remark',
            'assigned_to',
            'to_user',
        ],
    ],

    // ================================================================
    //  Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ================================================================
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由。
        //
        // 默认关闭（opt-in，安全审计阶段 1 / A）：本包自带状态变更 HTTP 接口，
        // 若默认开启而宿主又未声明认证中间件，等于把「改状态的接口」匿名暴露
        // （框架中「不声明 middleware」就等价于匿名可达）。
        // 需要 HTTP 接口时，请在宿主 config/wise-workflow.php 显式开启并声明认证。
        'enable' => false,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'api/workflow',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 显式允许「无认证中间件」的匿名模式（fail-closed 的逃生阀）。
        //   false（默认）—— 分组未声明任何中间件时**拒绝注册**该路由组，
        //                    避免误把状态变更接口匿名暴露（安全审计：默认拒绝）。
        //   true          —— 照原样注册并打告警；仅当由上游网关统一鉴权时才开启。
        'allow_anonymous' => false,
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            'workflow' => [
                'enable'     => true,
                'middleware' => [],
            ],
        ],
    ],
];
