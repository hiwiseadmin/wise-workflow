<?php
declare(strict_types=1);

namespace wise\workflow;

use think\facade\Cache;

/**
 * 工作流注册表（Workflow Registry）
 * 
 * 集中管理工作流定义的注册中心
 * 支持静态注册、数据库动态加载和缓存
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
     * 表达式守卫求值器缓存
     * @var ExpressionGuard|null
     */
    protected static $expressionGuard;

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
        $cacheEnabled = config('wise-workflow.cache.enabled', false);
        $cacheTtl     = (int)config('wise-workflow.cache.ttl', 3600);
        $cacheKey     = 'wise_workflow_definitions';

        $definitions = null;

        if ($cacheEnabled) {
            try {
                $definitions = Cache::get($cacheKey);
            } catch (\Throwable $e) {
                // 缓存不可用时静默回退
            }
        }

        if ($definitions === null || $definitions === false) {
            $definitions = model\WorkflowDefinition::where('status', 1)
                ->order('id', 'asc')
                ->select();

            if ($cacheEnabled && $cacheTtl > 0) {
                try {
                    Cache::set($cacheKey, $definitions, $cacheTtl);
                } catch (\Throwable $e) {
                    // 缓存写入失败静默回退
                }
            }
        }

        foreach ($definitions as $def) {
            $placesData      = json_decode($def['places'], true) ?: [];
            $transitionsData = json_decode($def['transitions'], true) ?: [];

            // 将数组转换为 Place 对象（支持元数据）
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

            // 将数组转换为 Transition 对象（支持 guard_expression 和元数据）
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
                'version'        => $def['version'] ?? 0,
                'version_policy' => $def['version_policy'] ?? 'strict',
                'sla_config'     => json_decode($def['sla_config'] ?? '{}', true) ?: [],
            ]);
            static::add($workflow);
        }
    }

    /**
     * 清除工作流定义缓存
     */
    public static function clearCache(): bool
    {
        try {
            return Cache::delete('wise_workflow_definitions');
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
}
