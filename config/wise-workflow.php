<?php
/**
 * WiseWorkflow 配置文件
 * 
 * 通过 config('wise-workflow.xxx') 读取配置项
 */
return [
    // 数据库连接（空=默认）
    'connection'  => '',
    // 数据表名称映射
    'table_names' => [
        'workflow'  => 'workflow_definition',
        'marking'   => 'workflow_marking',
        'history'   => 'workflow_history',
        'task'      => 'workflow_task',
    ],
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
];
