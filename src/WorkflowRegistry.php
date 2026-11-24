<?php
declare(strict_types=1);

namespace wise\workflow;

use think\facade\Cache;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\guard\ExpressionGuard;

/**
 * 工作流注册表（Workflow Registry）
 *
 * 集中管理工作流定义和管道扩展的注册中心。
 * 支持静态注册、数据库动态加载和缓存。
 *
 * V2 新增功能：
 * - registerPipe()    插件注册自定义 Pipe 到命名插槽
 * - registerPipeFor() 插件注册 Pipe 到特定工作流
 * - registerPipeline()注册自定义管道组合
 * - getRegisteredPipes() 获取所有已注册的插件 Pipe
 *
 * 版本管理策略：
 * - strict:  版本不一致直接拒绝
 * - migrate: 自动将旧版本实例迁移到新路径
 * - legacy:  旧实例沿用旧定义运行完毕
 *
 * @package wise\workflow
 */
class WorkflowRegistry
{
    /**
     * 已注册的工作流集合
     * @var array<string, Workflow>
     */
    protected static $workflows = [];

    /**
     * 上一轮「从数据库装载」的定义名
     *
     * 重新装载前只失效这些条目，保留插件 / 业务通过 add() 注册的代码级定义
     * （插件 BootServiceProvider 普遍在启动时 add()）。
     * @var array<string, true>
     */
    protected static $dbLoaded = [];

    /**
     * 表达式守卫求值器缓存
     * @var ExpressionGuard|null
     */
    protected static $expressionGuard;

    /**
     * V2: 插件注册的管道（按插槽分组）
     * @var array<string, array<string>>  ['before_transition' => ['pipe1', 'pipe2'], ...]
     */
    protected static $registeredPipes = [];

    /**
     * V2: 插件注册的特定工作流管道
     * @var array<string, array>  ['workflowName' => ['after' => ['PipeClass' => ['pipe']]]]
     */
    protected static $workflowPipes = [];

    /**
     * V2: 插件注册的自定义管道组合
     * @var array<string, array<string>>  ['pipelineName' => ['pipe1', 'pipe2'], ...]
     */
    protected static $customPipelines = [];

    /**
     * 注册一个工作流
     */
    public static function add(Workflow $workflow): void
    {
        static::$workflows[$workflow->getName()] = $workflow;
    }

    /**
     * 获取指定名称的工作流
     */
    public static function get(string $name): Workflow
    {
        if (!isset(static::$workflows[$name])) {
            throw new exception\WorkflowException("Workflow [{$name}] not found");
        }
        return static::$workflows[$name];
    }

    /**
     * 获取所有已注册的工作流
     */
    public static function all(): array
    {
        return static::$workflows;
    }

    /**
     * 判断工作流是否存在
     */
    public static function has(string $name): bool
    {
        return isset(static::$workflows[$name]);
    }

    /**
     * 清除所有已注册的工作流
     */
    public static function clear(): void
    {
        static::$workflows = [];
        static::$dbLoaded  = [];
    }

    /**
     * 从数据库加载工作流定义（带缓存）
     *
     * 缓存策略：
     * - 优先从缓存读取
     * - 缓存未命中时查 DB 并写入缓存
     * - TTL 可通过 wise-workflow.cache.ttl 配置
     */
    public static function loadFromDatabase(): void
    {
        foreach (array_keys(static::$dbLoaded) as $loadedName) {
            unset(static::$workflows[$loadedName]);
        }
        static::$dbLoaded = [];

        $cacheEnabled = config('wise-workflow.cache.enabled', false);
        $cacheTtl     = (int)config('wise-workflow.cache.ttl', 3600);
        $cacheKey     = 'wise_workflow_definitions_v2';

        $definitions = null;

        if ($cacheEnabled) {
            try {
                $definitions = Cache::get($cacheKey);
            } catch (\Throwable $e) {
            }
        }

        if ($definitions === null || $definitions === false) {
            $definitions = model\WorkflowDefinition::where('status', 1)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            if ($cacheEnabled && $cacheTtl > 0) {
                try {
                    Cache::set($cacheKey, $definitions, $cacheTtl);
                } catch (\Throwable $e) {
                }
            }
        }

        foreach ($definitions as $def) {
            $placesData      = json_decode($def['places'], true) ?: [];
            $transitionsData = json_decode($def['transitions'], true) ?: [];

            $places = [];
            foreach ($placesData as $placeData) {
                if (is_string($placeData)) {
                    $places[] = new Place($placeData);
                } elseif (is_array($placeData)) {
                    $name     = $placeData['name'] ?? ($placeData[0] ?? '');
                    $metadata = $placeData['metadata'] ?? [];
                    $places[] = new Place((string)$name, (array)$metadata);
                }
            }

            $transitions = [];
            foreach ($transitionsData as $trans) {
                $name            = $trans['name'] ?? '';
                $from            = $trans['from'] ?? [];
                $to              = $trans['to'] ?? [];
                $metadata        = $trans['metadata'] ?? [];
                $guardExpression = $trans['guard_expression'] ?? null;

                $transition = new Transition($name, $from, $to, null, $metadata, $guardExpression);
                $transitions[] = $transition;
            }

            $workflow = new Workflow(
                $def['name'],
                $places,
                $transitions,
                $def['initial_place']
            );
            $workflow->setMetadata([
                'title'          => $def['title'],
                'description'    => $def['description'],
                'id'             => $def['id'],
                'version'        => $def['version'] ?? 1,
                'version_policy' => $def['version_policy'] ?? 'strict',
                'sla_config'     => json_decode($def['sla_config'] ?? '{}', true) ?: [],
            ]);
            static::add($workflow);
            static::$dbLoaded[$workflow->getName()] = true;
        }
    }

    /**
     * 清除工作流定义缓存
     */
    public static function clearCache(): bool
    {
        try {
            return Cache::delete('wise_workflow_definitions_v2');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取表达式守卫求值器
     */
    public static function getExpressionGuard(): ExpressionGuard
    {
        if (static::$expressionGuard === null) {
            static::$expressionGuard = new ExpressionGuard();
        }
        return static::$expressionGuard;
    }

    /**
     * 构建 Transition 的守卫条件（合并 callable guard 和 guard_expression）
     */
    public static function buildGuard(Transition $transition): ?callable
    {
        $callableGuard   = $transition->getGuard();
        $guardExpression = $transition->getGuardExpression();

        if ($callableGuard && !$guardExpression) {
            return $callableGuard;
        }

        if ($guardExpression && !$callableGuard) {
            $expressionGuard = static::getExpressionGuard();
            return function (array $marking, array $context) use ($expressionGuard, $guardExpression): bool {
                return $expressionGuard->evaluate($guardExpression, $marking, $context);
            };
        }

        if ($callableGuard && $guardExpression) {
            $expressionGuard = static::getExpressionGuard();
            return function (array $marking, array $context) use ($callableGuard, $expressionGuard, $guardExpression): bool {
                return $callableGuard($marking, $context)
                    && $expressionGuard->evaluate($guardExpression, $marking, $context);
            };
        }

        return null;
    }


    /**
     * 注册自定义 Pipe 到命名插槽（插件常用）
     *
     * 插槽名对应标准 Pipe 类名去掉 Pipe 后缀 + 前缀：
     *   - 'before_{PipeBaseName}'  插入到该 Pipe 之前
     *   - 'after_{PipeBaseName}'   插入到该 Pipe 之后
     *
     * 示例：
     * <pre>
     * WorkflowRegistry::registerPipe(
     *     slot: 'before_transition',
     *     pipe: \plugin\finance\pipes\CheckBudgetPipe::class,
     *     priority: 100
     * );
     * </pre>
     *
     * @param string $slot     插槽名称（如 'before_transition', 'after_save'）
     * @param string $pipe      管道类名
     * @param int    $priority  优先级（数字越小越靠前，默认 0）
     */
    public static function registerPipe(string $slot, string $pipe, int $priority = 0): void
    {
        if (!isset(static::$registeredPipes[$slot])) {
            static::$registeredPipes[$slot] = [];
        }

        $key = $slot . '_' . $priority . '_' . $pipe;
        static::$registeredPipes[$slot][$key] = $pipe;
    }

    /**
     * 注册 Pipe 到特定工作流的指定位置（插件精确控制）
     *
     * 示例：
     * <pre>
     * WorkflowRegistry::registerPipeFor(
     *     workflow: 'order_approval',
     *     pipe: \plugin\finance\pipes\CheckBudgetPipe::class,
     *     after: 'CheckGuardsPipe',
     * );
     * </pre>
     *
     * @param string      $workflow 工作流名称
     * @param string      $pipe     管道类名
     * @param string|null $after    插入在指定 Pipe 之后（Pipe 简单类名）
     * @param string|null $before   插入在指定 Pipe 之前（Pipe 简单类名）
     */
    public static function registerPipeFor(
        string $workflow,
        string $pipe,
        ?string $after = null,
        ?string $before = null
    ): void {
        if (!isset(static::$workflowPipes[$workflow])) {
            static::$workflowPipes[$workflow] = [];
        }

        if ($after !== null) {
            $slot = 'after_' . $after;
            if (!isset(static::$workflowPipes[$workflow][$slot])) {
                static::$workflowPipes[$workflow][$slot] = [];
            }
            static::$workflowPipes[$workflow][$slot][] = $pipe;
        }

        if ($before !== null) {
            $slot = 'before_' . $before;
            if (!isset(static::$workflowPipes[$workflow][$slot])) {
                static::$workflowPipes[$workflow][$slot] = [];
            }
            static::$workflowPipes[$workflow][$slot][] = $pipe;
        }
    }

    /**
     * 注册自定义管道组合（插件完整替换管道列表）
     *
     * @param string        $name  管道组合名称
     * @param array<string> $pipes 管道类名列表（完整有序）
     */
    public static function registerPipeline(string $name, array $pipes): void
    {
        static::$customPipelines[$name] = $pipes;
    }

    /**
     * 获取插件注册的所有 Pipe（按插槽分组）
     *
     * @return array<string, array>
     */
    public static function getRegisteredPipes(): array
    {
        $result = [];
        foreach (static::$registeredPipes as $slot => $pipes) {
            ksort($pipes);
            $result[$slot] = array_values($pipes);
        }
        return $result;
    }

    /**
     * 获取特定工作流的插件注册 Pipe
     *
     * @param string $workflowName 工作流名称
     * @return array
     */
    public static function getWorkflowPipes(string $workflowName): array
    {
        return static::$workflowPipes[$workflowName] ?? [];
    }

    /**
     * 获取自定义管道组合
     *
     * @param string $name 管道组合名称
     * @return array<string>|null
     */
    public static function getCustomPipeline(string $name): ?array
    {
        return static::$customPipelines[$name] ?? null;
    }

    /**
     * 清除所有插件注册的 Pipe（V2 新增，通常用于测试）
     */
    public static function clearRegisteredPipes(): void
    {
        static::$registeredPipes = [];
        static::$workflowPipes   = [];
        static::$customPipelines = [];
    }
}
