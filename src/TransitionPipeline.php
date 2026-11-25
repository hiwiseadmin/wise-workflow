<?php
declare(strict_types=1);

namespace wise\workflow;

use Closure;
use wise\support\Pipeline as BasePipeline;
use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\exception\WorkflowException;
use wise\workflow\task\TaskManager;

/**
 * 转换管道（Transition Pipeline）
 *
 * 继承 wise\support\Pipeline 的洋葱模型管道引擎，
 * 叠加 workflow 特有的 Pipe 反射注入和载荷类型约束。
 *
 * 审批流程的编排器，将 WorkflowEngine::apply() 中的巨型方法
 * 拆分为独立的 Pipe 链，通过洋葱模型依次执行。
 *
 * 核心机制：
 *   - 继承自 BasePipeline 的 send/through/via/then/thenReturn
 *   - 重写 carry() 以支持 workflow 特有的 Class:param 格式和反射注入
 *   - send() 添加 TransitionPayload 类型约束
 *   - 任何 Pipe 抛出 WorkflowException 即中止管道
 *
 * 使用示例：
 * <pre>
 * $pipeline = new TransitionPipeline($markingStore);
 * $result = $pipeline
 *     ->send(new TransitionPayload($workflow, $subject, $transitionName, $context))
 *     ->through($pipes)
 *     ->then(function (TransitionPayload $p) {
 *         return $p->marking;
 *     });
 * </pre>
 *
 * @package wise\workflow
 */
class TransitionPipeline extends BasePipeline
{
    /**
     * 状态存储（供 Pipe 内部使用）
     * @var MarkingStoreInterface
     */
    protected $markingStore;

    /**
     * 事件服务
     * @var object|null
     */
    protected $eventService;

    /**
     * 权限服务
     * @var object|null
     */
    protected $permissionService;

    /**
     * 历史记录模型
     * @var object|null
     */
    protected $historyModel;

    /**
     * 任务管理器
     * @var TaskManager|null
     */
    protected $taskManager;

    /**
     * 乐观锁配置
     * @var array
     */
    protected $lockConfig;

    /**
     * 构造函数
     */
    public function __construct(
        MarkingStoreInterface $markingStore,
        $eventService = null,
        $permissionService = null,
        $historyModel = null,
        ?TaskManager $taskManager = null
    ) {
        $this->markingStore      = $markingStore;
        $this->eventService      = $eventService;
        $this->permissionService = $permissionService;
        $this->historyModel      = $historyModel;
        $this->taskManager       = $taskManager;
        $this->lockConfig        = config('wise-workflow.lock', ['enabled' => true, 'max_retries' => 3]);
    }


    public function getMarkingStore(): MarkingStoreInterface
    {
        return $this->markingStore;
    }

    /**
     * @return object|null
     */
    public function getEventService()
    {
        return $this->eventService;
    }

    /**
     * @return object|null
     */
    public function getPermissionService()
    {
        return $this->permissionService;
    }

    /**
     * @return object|null
     */
    public function getHistoryModel()
    {
        return $this->historyModel;
    }

    public function getTaskManager(): ?TaskManager
    {
        return $this->taskManager;
    }

    public function getLockConfig(): array
    {
        return $this->lockConfig;
    }


    /**
     * 设置管道载荷（类型约束为 TransitionPayload）
     */
    public function send($passable): self
    {
        return parent::send($passable);
    }

    /**
     * 所有管道通过后原样返回 payload
     */
    public function thenReturn(): TransitionPayload
    {
        return $this->then(function (TransitionPayload $payload) {
            return $payload;
        });
    }


    /**
     * 洋葱包裹器（重写以支持 workflow 特有的 pipe 解析）
     *
     * 支持：
     *  - Closure → 直接作为 pipe
     *  - 类名字符串 → 通过 resolvePipeInstance() 反射注入依赖
     *  - Class:param1,param2 格式 → 解析类名和参数
     *  - 对象 → 直接调用
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function (TransitionPayload $payload) use ($stack, $pipe) {
                if ($pipe instanceof Closure) {
                    return $pipe($payload, $stack);
                }

                $parameters = [];
                if (is_string($pipe)) {
                    $segments = explode(':', $pipe);
                    $pipe = $segments[0];
                    $parameters = array_slice($segments, 1);
                }

                if (is_object($pipe)) {
                    $instance = $pipe;
                } else {
                    $instance = $this->resolvePipeInstance($pipe, $parameters);
                }

                return call_user_func_array([$instance, $this->method], [$payload, $stack]);
            };
        };
    }

    /**
     * 解析 Pipe 实例（workflow 特有的反射注入）
     *
     * 如果 Pipe 类实现了 TransitionPipeInterface，自动注入：
     *  - TransitionPipeline 实例
     *  - MarkingStoreInterface 实例
     *
     * @param string $pipeClass  Pipe 类名
     * @param array  $parameters 附加参数
     * @return object
     */
    protected function resolvePipeInstance(string $pipeClass, array $parameters = []): object
    {
        if (!class_exists($pipeClass)) {
            throw new WorkflowException("管道工序类不存在: [{$pipeClass}]");
        }

        if (!is_subclass_of($pipeClass, TransitionPipeInterface::class)) {
            throw new WorkflowException("管道工序类必须实现 TransitionPipeInterface: [{$pipeClass}]");
        }

        try {
            $reflection = new \ReflectionClass($pipeClass);
            $constructor = $reflection->getConstructor();

            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                return new $pipeClass();
            }

            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if ($type === null) {
                    $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                    continue;
                }

                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

                switch ($typeName) {
                    case TransitionPipeline::class:
                        $args[] = $this;
                        break;
                    case MarkingStoreInterface::class:
                        $args[] = $this->markingStore;
                        break;
                    default:
                        $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                        break;
                }
            }

            return $reflection->newInstanceArgs($args);

        } catch (\ReflectionException $e) {
            throw new WorkflowException("无法解析管道工序实例: [{$pipeClass}], 原因: {$e->getMessage()}");
        }
    }
}
