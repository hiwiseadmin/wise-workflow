<?php
declare(strict_types=1);

namespace wise\workflow\contract;

/**
 * 状态存储接口（Marking Store Interface）
 * 
 * 定义工作流状态存储的标准接口
 * 实现类负责将工作流的状态（标记）持久化到不同存储后端
 * 
 * @package wise\workflow
 */
interface MarkingStoreInterface
{
    /**
     * 获取指定主体的当前标记（状态）
     * 
     * @param string $subject 主体标识
     * @return array 当前标记 ['place_name' => 1, ...]
     * @throws \wise\workflow\exception\WorkflowException 乐观锁冲突时
     */
    public function getMarking(string $subject): array;

    /**
     * 设置指定主体的标记（状态）
     * 
     * @param string $subject 主体标识
     * @param array  $marking 新的标记
     * @param array  $context 上下文
     * @param int    $expectedVersion 乐观锁版本号（0=不检查）
     * @return int  实际写入的版本号
     * @throws \wise\workflow\exception\WorkflowException 乐观锁冲突时
     */
    public function setMarking(string $subject, array $marking, array $context = [], int $expectedVersion = 0): int;

    /**
     * 记录转换历史
     * 
     * @param string $subject        主体标识
     * @param string $transitionName 转换名称
     * @param string $fromPlace      源状态
     * @param string $toPlace        目标状态
     * @param array  $context        上下文
     * @param string $type           操作类型
     * @return void
     */
    public function recordHistory(
        string $subject,
        string $transitionName,
        string $fromPlace,
        string $toPlace,
        array $context = [],
        string $type = 'normal'
    ): void;

    /**
     * 获取当前版本号（用于乐观锁）
     * 
     * @param string $subject 主体标识
     * @return int 版本号，不存在时返回 0
     */
    public function getVersion(string $subject): int;

    /**
     * 设置处理人
     *
     * @param string $subject 主体标识
     * @param int    $userId  用户ID
     * @return void
     */
    public function setAssignee(string $subject, int $userId): void;

    /**
     * 获取实例记录的定义版本号
     *
     * 用于 VerifyVersionPipe 校验实例与当前定义版本是否一致。
     * 与乐观锁版本号（version 字段）不同，此方法返回的是
     * 工作流定义本身的版本（definition_version 字段）。
     *
     * @param string $subject 主体标识
     * @return int 定义版本号，不存在时返回 0
     */
    public function getDefinitionVersion(string $subject): int;
}
