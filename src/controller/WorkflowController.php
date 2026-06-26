<?php
declare(strict_types=1);

namespace wise\workflow\controller;

use think\facade\Request;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\MarkingStoreInterface;
use wise\workflow\TaskManager;
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
        $this->engine       = new WorkflowEngine($this->markingStore, null, null, null, $this->taskManager);
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

        return $this->success($data);
    }

    /**
     * 获取单个工作流定义详情
     */
    public function definition(string $name)
    {
        WorkflowRegistry::loadFromDatabase();

        if (!WorkflowRegistry::has($name)) {
            return $this->error("工作流 [{$name}] 不存在", 404);
        }

        $workflow = WorkflowRegistry::get($name);
        return $this->success($workflow->toArray());
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
            return $this->error("工作流 [{$name}] 不存在", 404);
        }

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

        return $this->success([
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
        $limit   = (int)Request::get('limit', 50);
        $history = $this->engine->getHistory($subject, $limit);

        return $this->success([
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
            return $this->error("工作流 [{$name}] 不存在", 404);
        }

        $transitionName = Request::post('transition', '');
        $context        = Request::post('context', []);
        $remark         = Request::post('remark', '');
        $context['remark'] = $remark;

        if (empty($transitionName)) {
            return $this->error('缺少 transition 参数', 422);
        }

        try {
            $workflow   = WorkflowRegistry::get($name);
            $newMarking = $this->engine->apply($workflow, $subject, $transitionName, $context);

            return $this->success([
                'subject'    => $subject,
                'transition' => $transitionName,
                'marking'    => $newMarking,
                'message'    => "转换 [{$transitionName}] 执行成功",
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400, $e->getData());
        } catch (\Throwable $e) {
            return $this->error('转换执行失败: ' . $e->getMessage(), 500);
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
        $pendingTasks = $this->engine->getPendingTasks($subject);

        return $this->success([
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
        $userId = Request::get('user_id', 0);
        $status = Request::get('status', '');

        if (empty($userId)) {
            return $this->error('缺少 user_id 参数', 422);
        }

        $tasks = $this->engine->getUserTasks((int)$userId, $status);

        return $this->success([
            'user_id' => (int)$userId,
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
            return $this->error("工作流 [{$name}] 不存在", 404);
        }

        $transitionName = Request::post('transition', '');
        $assignees      = Request::post('assignees', []);
        $policy         = Request::post('policy', 'all');
        $policyValue    = Request::post('policy_value', null);
        $context        = Request::post('context', []);

        if (empty($transitionName)) {
            return $this->error('缺少 transition 参数', 422);
        }
        if (empty($assignees) || !is_array($assignees)) {
            return $this->error('缺少 assignees 参数或格式错误', 422);
        }

        try {
            $workflow = WorkflowRegistry::get($name);
            $tasks = $this->engine->createTasks(
                $workflow, $subject, $transitionName, $assignees, $policy, $policyValue, $context
            );

            return $this->success([
                'subject'    => $subject,
                'transition' => $transitionName,
                'policy'     => $policy,
                'total'      => count($tasks),
                'tasks'      => $tasks,
                'message'    => "已创建 " . count($tasks) . " 个审批任务",
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('创建任务失败: ' . $e->getMessage(), 500);
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
            return $this->error('缺少 transition 参数', 422);
        }
        if (empty($userId)) {
            return $this->error('缺少 user_id 参数', 422);
        }

        try {
            $transitioned = $this->engine->completeTask(
                $subject, $transitionName, $key, (int)$userId, $result, ['comment' => $comment]
            );

            return $this->success([
                'subject'      => $subject,
                'task_key'     => $key,
                'result'       => $result,
                'transitioned' => $transitioned,
                'message'      => $transitioned
                    ? '审批完成，工作流已自动流转'
                    : '审批已记录，等待其他审批人',
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('完成任务失败: ' . $e->getMessage(), 500);
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
            return $this->error('缺少 transition 参数', 422);
        }
        if (empty($fromUserId) || empty($toUserId)) {
            return $this->error('缺少 from_user 或 to_user 参数', 422);
        }

        try {
            $ok = $this->engine->delegateTask(
                $subject, $transitionName, $key, (int)$fromUserId, (int)$toUserId
            );

            if (!$ok) {
                return $this->error('任务不存在或状态不允许转交', 400);
            }

            return $this->success([
                'subject'    => $subject,
                'task_key'   => $key,
                'from_user'  => $fromUserId,
                'to_user'    => $toUserId,
                'message'    => "已转交给用户 [{$toUserId}]",
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('转交任务失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 取消待处理任务
     * POST /api/workflow/:name/:subject/task/cancel
     */
    public function cancelTasks(string $name, string $subject)
    {
        $transitionName = Request::post('transition', '');

        try {
            $ok = $this->engine->cancelTasks($subject, $transitionName);

            return $this->success([
                'subject'    => $subject,
                'canceled'   => $ok,
                'message'    => $ok ? '任务已取消' : '无待处理任务',
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('取消任务失败: ' . $e->getMessage(), 500);
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
            return $this->error('缺少 to_user 参数', 422);
        }

        try {
            WorkflowRegistry::loadFromDatabase();

            if (!WorkflowRegistry::has($name)) {
                return $this->error("工作流 [{$name}] 不存在", 404);
            }

            $workflow = WorkflowRegistry::get($name);
            $this->engine->delegate($workflow, $subject, (int)$toUserId, [
                'remark' => $remark,
            ]);

            return $this->success([
                'subject'  => $subject,
                'to_user'  => $toUserId,
                'message'  => "已委托给用户 [{$toUserId}]",
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('委托失败: ' . $e->getMessage(), 500);
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
                return $this->error("工作流 [{$name}] 不存在", 404);
            }

            $workflow   = WorkflowRegistry::get($name);
            $newMarking = $this->engine->rollback($workflow, $subject, [
                'remark' => $remark,
            ]);

            return $this->success([
                'subject'  => $subject,
                'marking'  => $newMarking,
                'message'  => '回滚成功',
            ]);
        } catch (WorkflowException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('回滚失败: ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────

    protected function success($data = [], string $message = 'ok'): \think\response\Json
    {
        return json([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    protected function error(string $message = 'error', int $code = 400, $data = null): \think\response\Json
    {
        $result = [
            'code'    => $code,
            'message' => $message,
        ];
        if ($data !== null) {
            $result['data'] = $data;
        }
        return json($result, $code);
    }
}
