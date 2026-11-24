<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\exception\WorkflowException;

/**
 * 二次确认管道（Check Confirm Pipe）
 *
 * 转换元数据声明 `confirm: true` 时，要求调用方显式确认后才允许执行。
 * 此前 `Transition::requireConfirm()` / `getConfirmMessage()` 只被前端消费，
 * 服务端从不校验——任何直连 API 的调用方都能跳过「危险操作二次确认」这一步。
 *
 * 语义边界：
 *   - 只认 `context.confirmed === true`（严格布尔）：避免误把 "false" / 1 / "1"
 *     当成已确认（HTTP 表单与 JSON 两种请求体的差异全部在控制器收敛）；
 *   - 系统自动触发（SLA 的 auto_reject / escalate）不存在人工确认环节，直接放行；
 *   - 二次确认是 **UX 层护栏**，不是授权：谁能执行该转换由 CheckPermissionPipe 决定。
 *
 * @package wise\workflow\pipes
 */
class CheckConfirmPipe implements TransitionPipeInterface
{
    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        $transition = $payload->transition;

        if ($transition === null || !$transition->requireConfirm()) {
            $payload->log(__CLASS__, 'skipped', '无二次确认要求');
            return $next($payload);
        }

        if (!empty($payload->context['auto_sla'])) {
            $payload->log(__CLASS__, 'skipped', '系统自动触发(auto_sla)，跳过二次确认');
            return $next($payload);
        }

        if (($payload->context['confirmed'] ?? false) !== true) {
            $payload->log(__CLASS__, 'failed', '缺少二次确认');
            throw new WorkflowException(
                $transition->getConfirmMessage(),
                [
                    'need_confirm' => true,
                    'transition'   => $payload->transitionName,
                    'workflow'     => $payload->getWorkflowName(),
                ]
            );
        }

        $payload->log(__CLASS__, 'passed', '已二次确认');
        return $next($payload);
    }
}
