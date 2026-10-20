<?php
declare(strict_types=1);

namespace wise\workflow\contract;

/**
 * 管道工序接口（Transition Pipe Interface）
 * 
 * 每个 Pipe 负责审批流程中的一个独立步骤。
 * 参考 illuminate/pipeline 的洋葱模型设计：
 * 
 *    请求穿过：Pipe1 前置 → Pipe2 前置 → ... → 核心回调
 *    响应穿出：核心回调 → ... → Pipe2 后置 → Pipe1 后置
 * 
 * 三种返回值语义：
 *   - return $next($payload)          继续传递给下一道工序
 *   - throw WorkflowException         终止管道（审批失败）
 *   - return $payload                 提前返回，不再执行后续（特殊场景，慎用）
 * 
 * @package wise\workflow\contract
 */
interface TransitionPipeInterface
{
    /**
     * 处理审批流程的一个步骤
     *
     * @param TransitionPayload $payload  管道载荷（携带 workflow/subject/transition/marking/context 等）
     * @param \Closure          $next     传递给下一个 pipe 的回调
     * @return TransitionPayload
     * @throws \wise\workflow\exception\WorkflowException  可抛出异常中止管道
     */
    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload;
}
