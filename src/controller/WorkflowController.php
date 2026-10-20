<?php
declare(strict_types=1);

namespace wise\workflow\controller;

use wise\support\context\ContextualLogger;
use think\facade\Request;
use think\facade\Session;
use think\response\Json;
use wise\support\traits\JsonResponse;
use wise\workflow\support\ResponseSanitizer;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\task\TaskManager;
use wise\workflow\exception\WorkflowException;

/**
 * 工作流 RESTful API 控制器 v2.1
 * 
 * 提供标准化的 HTTP API 端点用于：
 * - 查询工作流定义
 * - 查看实例状态（含并行审批任务）
 * - 执行转换
 * - 查询历史
 * - 并行审批任务操作
 * - 委托/转交（含并行任务转交）
 * - 回滚
 * 
 * 路由示例（plugin.php 或 api.php）：
 * ```
 * Route::group('api/workflow', function() {
 *     Route::get('definitions', '...@definitions');
 *     Route::get('definitions/:name', '...@definition');
 *     Route::get(':name/:subject', '...@status');
 *     Route::get(':name/:subject/history', '...@history');
 *     Route::get(':name/:subject/tasks', '...@tasks');
 *     Route::post(':name/:subject/apply', '...@apply');
 *     Route::post(':name/:subject/task/create', '...@createTasks');
 *     Route::post(':name/:subject/task/:key/complete', '...@completeTask');
 *     Route::post(':name/:subject/task/:key/delegate', '...@delegateTask');
 *     Route::post(':name/:subject/task/cancel', '...@cancelTasks');
 *     Route::post(':name/:subject/delegate', '...@delegate');
 *     Route::post(':name/:subject/rollback', '...@rollback');
 *     Route::get('user-tasks', '...@userTasks');
 * })->allowCrossDomain();
 * ```
 * 
 * @package wise\workflow\controller
 */
class WorkflowController
{
    use JsonResponse {
        // 保留 trait 原实现，供下方收口用的 success() 调用
        success as private traitSuccess;
    }

    /**
     * 成功响应统一收口（安全审计阶段 3 / E-2）
     *
     * 读端点此前会把内部策略字段（`guard_expression`、`metadata.sla_config`）与
     * 操作者痕迹（`ip` / `user_agent`）原样回显。所有成功响应在这里统一裁剪，
     * 因此后续新增的读端点自动受保护。裁剪字段见配置 `response.sensitive_fields`。
     */
    protected function success(string $msg = 'ok', $data = [], int $code = 0): Json|string
    {
        return $this->traitSuccess($msg, ResponseSanitizer::sanitize($data), $code);
    }

    /**
     * 工作流引擎
     * @var WorkflowEngine
     */
    protected $engine;

    /**
     * 状态存储
     * @var MarkingStoreInterface
     */
    protected $markingStore;

    /**
     * 任务管理器
     * @var TaskManager
     */
    protected $taskManager;

    public function __construct()
    {
        $this->markingStore = app()->make(MarkingStoreInterface::class);
        $this->taskManager  = app()->make(TaskManager::class);

        // 安全获取事件服务（CLI 或未注册时静默回退）
        $eventService = null;
        try {
            $eventService = app()->has('think\Event')
                ? app()->make('think\Event')
                : (app()->has('events') ? app()->make('events') : null);
        } catch (\Throwable $e) {
            // 事件服务不可用时静默回退
        }

        // 安全获取权限服务
        $permissionService = null;
        try {
            if (app()->has('permission')) {
                $permissionService = app()->make('permission');
            }
        } catch (\Throwable $e) {
            // 权限服务不可用时静默回退
        }

        $this->engine = new WorkflowEngine(
            $this->markingStore,
            $eventService,
            $permissionService,
            null,
            $this->taskManager
        );
    }

    // ────────────────────────────────────────────
    //  工作流定义
    // ────────────────────────────────────────────

    /**
     * 获取所有工作流定义列表
     */
    public function definitions()
    {
        WorkflowRegistry::loadFromDatabase();
        $workflows = WorkflowRegistry::all();
        $data = [];

        foreach ($workflows as $workflow) {
            $data[] = $workflow->toArray();
        }

        return $this->success('ok', $data);
    }

    /**
     * 获取单个工作流定义详情
     */
    public function definition(string $name)
    {
        WorkflowRegistry::loadFromDatabase();

        if (!WorkflowRegistry::has($name)) {
            return $this->error("工作流 [{$name}] 不存在", [], 404);
        }

        $workflow = WorkflowRegistry::get($name);
        return $this->success('ok', $workflow->toArray());
    }

    // ────────────────────────────────────────────
    //  实例状态与操作
    // ────────────────────────────────────────────

    /**
     * 获取实例当前状态（含并行审批任务信息）
     *
     * @param string $name    工作流名称
     * @param string $subject 主体标识
     */
    public function status(string $name, string $subject)
    {
        WorkflowRegistry::loadFromDatabase();

        if (!WorkflowRegistry::has($name)) {
            return $this->error("工作流 [{$name}] 不存在", [], 404);
        }

        // 安全（审计阶段 3 / C-1）：读路径同样校验实例归属（未配置归属服务时为空操作）
        $this->engine->checkSubjectAccess($subject);

        $workflow           = WorkflowRegistry::get($name);
        $marking            = $this->engine->getMarking($subject);
        $enabledTransitions = $this->engine->getEnabledTransitions($workflow, $subject);
        $pendingTasks       = $this->engine->getPendingTasks($subject);

        $transitionsData = [];
        foreach ($enabledTransitions as $t) {
            $data = $t->toArray();
            // 标注并行审批转换
            if ($t->isParallel()) {
                $data['is_parallel'] = true;
                $data['parallel']    = $t->getParallelConfig();
                // 检查是否有进行中的任务
                $transitionTasks = array_filter($pendingTasks, function ($task) use ($t) {
                    return $task['transition_name'] === $t->getName();
                });
                $data['pending_tasks'] = array_values($transitionTasks);
            }
            $transitionsData[] = $data;
        }

        // 当前状态节点
        $currentPlaces = [];
        foreach ($marking as $placeName => $val) {
            $place = $workflow->getPlaces()[$placeName] ?? null;
            $currentPlaces[] = $place ? $place->toArray() : ['name' => $placeName];
        }

        return $this->success('ok', [
            'subject'       => $subject,
            'workflow'      => $name,
            'current'       => $currentPlaces,
            'marking'       => $marking,
            'transitions'   => $transitionsData,
            'pending_tasks' => $pendingTasks,
            'version'       => $this->markingStore->getVersion($subject),
        ]);
    }

    /**
     * 获取实例历史记录
     */
    public function history(string $name, string $subject)
    {
        WorkflowRegistry::loadFromDatabase();

        if (!WorkflowRegistry::has($name)) {
            return $this->error("工作流 [{$name}] 不存在", [], 404);
        }

        // 安全（审计阶段 1 / E-3）：分页参数必须服务端夹取。
        // 原先 limit 无上下界，且 think-orm 的 parseLimit 用 !empty 判定，
        // limit=0 会退化为「不生成 LIMIT 子句」→ 一次请求可拉回该实例全部历史。
        // 安全（审计阶段 3 / C-1）：读路径同样校验实例归属
        $this->engine->checkSubjectAccess($subject);

        $limit   = min(max((int)Request::get('limit', 50), 1), 200);
        $history = $this->engine->getHistory($subject, $limit);

        return $this->success('ok', [
            'subject' => $subject,
            'total'   => count($history),
            'items'   => $history,
        ]);
    }

    /**
     * 执行工作流转换
     */
    public function apply(string $name, string $subject)
    {
        WorkflowRegistry::loadFromDatabase();

        if (!WorkflowRegistry::has($name)) {
            return $this->error("工作流 [{$name}] 不存在", [], 404);
        }

        $transitionName = Request::post('transition', '');
        $context        = Request::post('context', []);
        $remark         = Request::post('remark', '');
        $context['remark'] = $remark;

        if (empty($transitionName)) {
            return $this->error('缺少 transition 参数', [], 422);
        }

        try {
            $workflow   = WorkflowRegistry::get($name);
            $newMarking = $this->engine->apply($workflow, $subject, $transitionName, $context);

            return $this->success('ok', [
                'subject'    => $subject,
                'transition' => $transitionName,
                'marking'    => $newMarking,
                'message'    => "转换 [{$transitionName}] 执行成功",
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'apply');
        }
    }

    /**
     * 业务异常响应（安全审计阶段 1 / E-1）
     *
     * 诊断信息（message 中的内部事实 + getData() 的实例状态/版本号/表达式原文）
     * 只进日志；响应体不再携带 data，调用方只拿到面向用户的文案。
     *
     * @return mixed
     */
    private function workflowErrorResponse(WorkflowException $e, string $context)
    {
        ContextualLogger::warning("Workflow [{$context}] rejected: " . $e->getMessage(), [
            'data' => $e->getData(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return $this->error($e->getMessage(), [], 400);
    }

    /**
     * 当前登录用户 ID（安全审计阶段 2 / D-1、D-2）
     *
     * 授权判定必须以**认证主体**为依据：rollback / delegate / cancelTasks 的授权
     * 上下文必须带上它，否则权限服务无从判断「是谁在操作」。
     * 会话不可用时返回 0（调用方据此返回 401），绝不回退到请求参数。
     */
    private function currentUserId(): int
    {
        try {
            $sessionKey = config('wise-workflow.user_session_key', 'user.id');
            return (int) (Session::get($sessionKey) ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ────────────────────────────────────────────
    //  并行审批任务 API (Phase 3c)
    // ────────────────────────────────────────────

    /**
     * 获取实例的待处理任务列表
     * GET /api/workflow/:name/:subject/tasks
     */
    public function tasks(string $name, string $subject)
    {
        // 安全（审计阶段 3 / C-1）：读路径同样校验实例归属
        $this->engine->checkSubjectAccess($subject);

        $pendingTasks = $this->engine->getPendingTasks($subject);

        return $this->success('ok', [
            'subject' => $subject,
            'total'   => count($pendingTasks),
            'items'   => $pendingTasks,
        ]);
    }

    /**
     * 获取当前用户的待审批任务
     * GET /api/workflow/user-tasks?status=pending
     */
    public function userTasks()
    {
        // 从 Session/认证上下文中获取当前登录用户 ID，防止越权访问
        $userId = 0;
        try {
            $sessionKey = config('wise-workflow.user_session_key', 'user.id');
            $userId = (int)(Session::get($sessionKey) ?: 0);
        } catch (\Throwable $e) {
            // 安全（审计阶段 1 / C-4）：会话不可用时**不得**回退到请求参数 user_id，
            // 否则调用方可指定任意 user_id 枚举他人待办。这里视为未登录（下方返回 401）。
            $userId = 0;
        }

        if (empty($userId)) {
            return $this->error('无法获取当前用户身份，请先登录', [], 401);
        }

        $status = Request::get('status', '');
        $tasks = $this->engine->getUserTasks($userId, $status);

        return $this->success('ok', [
            'user_id' => $userId,
            'total'   => count($tasks),
            'items'   => $tasks,
        ]);
    }

    /**
     * 创建并行审批任务
     * POST /api/workflow/:name/:subject/task/create
     */
    public function createTasks(string $name, string $subject)
    {
        WorkflowRegistry::loadFromDatabase();

        if (!WorkflowRegistry::has($name)) {
            return $this->error("工作流 [{$name}] 不存在", [], 404);
        }

        $transitionName = Request::post('transition', '');
        $assignees      = Request::post('assignees', []);
        $policy         = Request::post('policy', 'all');
        $policyValue    = Request::post('policy_value', null);
        $context        = Request::post('context', []);

        if (empty($transitionName)) {
            return $this->error('缺少 transition 参数', [], 422);
        }
        if (empty($assignees) || !is_array($assignees)) {
            return $this->error('缺少 assignees 参数或格式错误', [], 422);
        }

        // 安全（审计阶段 3 / F-4）：入参收敛——去重、基数上限与策略枚举，超限 422。
        // TaskManager 内还有一层同样校验（防止绕过 HTTP 层直接调用引擎）。
        $assignees    = array_values(array_unique(array_map('intval', $assignees)));
        $maxAssignees = (int) config('wise-workflow.task.max_assignees', 50);

        if ($assignees === []) {
            return $this->error('assignees 不能为空', [], 422);
        }
        if (count($assignees) > $maxAssignees) {
            return $this->error("审批人数超出允许范围（最多 {$maxAssignees} 人）", [], 422);
        }
        if (!in_array($policy, ['all', 'any', 'ratio', 'count'], true)) {
            return $this->error("不支持的审批策略 [{$policy}]", [], 422);
        }

        try {
            $workflow = WorkflowRegistry::get($name);
            $tasks = $this->engine->createTasks(
                $workflow, $subject, $transitionName, $assignees, $policy, $policyValue, $context
            );

            return $this->success('ok', [
                'subject'    => $subject,
                'transition' => $transitionName,
                'policy'     => $policy,
                'total'      => count($tasks),
                'tasks'      => $tasks,
                'message'    => "已创建 " . count($tasks) . " 个审批任务",
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'createTasks');
        }
    }

    /**
     * 完成一个审批任务
     * POST /api/workflow/:name/:subject/task/:key/complete
     */
    public function completeTask(string $name, string $subject, string $key)
    {
        $transitionName = Request::post('transition', '');
        $userId         = Request::post('user_id', 0);
        $result         = Request::post('result', 'approved');
        $comment        = Request::post('comment', '');

        if (empty($transitionName)) {
            return $this->error('缺少 transition 参数', [], 422);
        }
        if (empty($userId)) {
            return $this->error('缺少 user_id 参数', [], 422);
        }

        try {
            $transitioned = $this->engine->completeTask(
                $subject, $transitionName, $key, (int)$userId, $result, ['comment' => $comment]
            );

            return $this->success('ok', [
                'subject'      => $subject,
                'task_key'     => $key,
                'result'       => $result,
                'transitioned' => $transitioned,
                'message'      => $transitioned
                    ? '审批完成，工作流已自动流转'
                    : '审批已记录，等待其他审批人',
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'completeTask');
        }
    }

    /**
     * 转交审批任务
     * POST /api/workflow/:name/:subject/task/:key/delegate
     */
    public function delegateTask(string $name, string $subject, string $key)
    {
        $transitionName = Request::post('transition', '');
        $fromUserId     = Request::post('from_user', 0);
        $toUserId       = Request::post('to_user', 0);

        if (empty($transitionName)) {
            return $this->error('缺少 transition 参数', [], 422);
        }
        if (empty($fromUserId) || empty($toUserId)) {
            return $this->error('缺少 from_user 或 to_user 参数', [], 422);
        }

        try {
            $ok = $this->engine->delegateTask(
                $subject, $transitionName, $key, (int)$fromUserId, (int)$toUserId
            );

            if (!$ok) {
                return $this->error('任务不存在或状态不允许转交', [], 400);
            }

            return $this->success('ok', [
                'subject'    => $subject,
                'task_key'   => $key,
                'from_user'  => $fromUserId,
                'to_user'    => $toUserId,
                'message'    => "已转交给用户 [{$toUserId}]",
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'delegateTask');
        }
    }

    /**
     * 取消待处理任务
     * POST /api/workflow/:name/:subject/task/cancel
     */
    public function cancelTasks(string $name, string $subject)
    {
        $transitionName = Request::post('transition', '');

        // 安全（审计阶段 2 / D-2）：撤销待办必须有明确的动作人
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->error('未登录', [], 401);
        }

        try {
            $ok = $this->engine->cancelTasks($subject, $transitionName, $userId);

            return $this->success('ok', [
                'subject'    => $subject,
                'canceled'   => $ok,
                'message'    => $ok ? '任务已取消' : '无待处理任务',
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'cancelTasks');
        }
    }

    // ────────────────────────────────────────────
    //  委托与回滚
    // ────────────────────────────────────────────

    /**
     * 委托/转交任务（引擎级）
     */
    public function delegate(string $name, string $subject)
    {
        $toUserId = Request::post('to_user', 0);
        $remark   = Request::post('remark', '');

        if (empty($toUserId)) {
            return $this->error('缺少 to_user 参数', [], 422);
        }

        try {
            WorkflowRegistry::loadFromDatabase();

            if (!WorkflowRegistry::has($name)) {
                return $this->error("工作流 [{$name}] 不存在", [], 404);
            }

            // 安全（审计阶段 2 / D-1）：带上动作人供统一授权点判定
            $userId = $this->currentUserId();
            if ($userId <= 0) {
                return $this->error('未登录', [], 401);
            }

            $workflow = WorkflowRegistry::get($name);
            $this->engine->delegate($workflow, $subject, (int)$toUserId, [
                'remark'  => $remark,
                'user_id' => $userId,
            ]);

            return $this->success('ok', [
                'subject'  => $subject,
                'to_user'  => $toUserId,
                'message'  => "已委托给用户 [{$toUserId}]",
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'delegate');
        }
    }

    /**
     * 回滚到上一个状态
     */
    public function rollback(string $name, string $subject)
    {
        $remark = Request::post('remark', '');

        try {
            WorkflowRegistry::loadFromDatabase();

            if (!WorkflowRegistry::has($name)) {
                return $this->error("工作流 [{$name}] 不存在", [], 404);
            }

            // 安全（审计阶段 2 / D-1）：带上动作人供统一授权点判定
            $userId = $this->currentUserId();
            if ($userId <= 0) {
                return $this->error('未登录', [], 401);
            }

            $workflow   = WorkflowRegistry::get($name);
            $newMarking = $this->engine->rollback($workflow, $subject, [
                'remark'  => $remark,
                'user_id' => $userId,
            ]);

            return $this->success('ok', [
                'subject'  => $subject,
                'marking'  => $newMarking,
                'message'  => '回滚成功',
            ]);
        } catch (WorkflowException $e) {
            return $this->workflowErrorResponse($e, __FUNCTION__);
        } catch (\Throwable $e) {
            return $this->logAndReturnError($e, 'rollback');
        }
    }

    /**
     * 记录异常日志并返回通用错误响应，避免敏感信息泄露
     *
     * @param \Throwable $e       异常对象
     * @param string     $context 操作上下文（方法名）
     * @return mixed
     */
    private function logAndReturnError(\Throwable $e, string $context)
    {
        ContextualLogger::error("Workflow [{$context}] failed: " . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return $this->error('系统内部错误，请稍后重试', [], 500);
    }

}
