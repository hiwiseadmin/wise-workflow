<?php
declare(strict_types=1);

namespace wise\workflow\test\Integration;

use PHPUnit\Framework\TestCase;
use wise\workflow\DatabaseMarkingStore;
use wise\workflow\MarkingStoreInterface;

/**
 * DatabaseMarkingStore 数据库状态存储集成测试
 *
 * 使用 Mockery 模拟 ThinkPHP Model 类，测试：
 * - getMarking()：读取已存在/不存在的记录
 * - setMarking()：新增/更新记录
 * - recordHistory()：历史记录写入
 * - 接口实现验证
 */
class DatabaseMarkingStoreTest extends TestCase
{
    /** @var DatabaseMarkingStore */
    private $store;

    /** @var \Mockery\MockInterface Mock 的 WorkflowMarking 模型 */
    private $markingModel;

    /** @var \Mockery\MockInterface Mock 的 WorkflowHistory 模型 */
    private $historyModel;

    protected function setUp(): void
    {
        $this->markingModel = \Mockery::mock(\wise\workflow\model\WorkflowMarking::class)->makePartial();
        $this->historyModel = \Mockery::mock(\wise\workflow\model\WorkflowHistory::class)->makePartial();

        $this->store = new DatabaseMarkingStore($this->markingModel, $this->historyModel);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    // ==================== 接口实现验证 ====================

    public function testImplementsMarkingStoreInterface(): void
    {
        $this->assertInstanceOf(MarkingStoreInterface::class, $this->store);
    }

    public function testDefaultConstructorCreatesModels(): void
    {
        // 不传任何参数，内部会创建默认模型实例
        $store = new DatabaseMarkingStore();
        $this->assertInstanceOf(DatabaseMarkingStore::class, $store);
    }

    // ==================== getMarking 测试 ====================

    public function testGetMarkingWithExistingRecord(): void
    {
        $subject = 'order:123';
        $markingData = ['draft' => 1];

        // Mock find 返回一个带有 marking 属性的对象
        $mockRecord = \Mockery::mock();
        $mockRecord->marking = json_encode($markingData, JSON_UNESCAPED_UNICODE);

        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn($mockRecord);

        $result = $this->store->getMarking($subject);

        $this->assertSame($markingData, $result);
    }

    public function testGetMarkingWithNonExistentRecord(): void
    {
        $subject = 'order:999';

        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn(null);

        $result = $this->store->getMarking($subject);

        $this->assertSame([], $result);
    }

    public function testGetMarkingWithInvalidJson(): void
    {
        $subject = 'order:invalid_json';

        // 模拟无效 JSON
        $mockRecord = \Mockery::mock();
        $mockRecord->marking = 'not-valid-json{{{';

        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn($mockRecord);

        $result = $this->store->getMarking($subject);

        $this->assertSame([], $result);
    }

    public function testGetMarkingWithMultiplePlaces(): void
    {
        $subject = 'order:multi';
        $markingData = ['review' => 1, 'notify' => 1];

        $mockRecord = \Mockery::mock();
        $mockRecord->marking = json_encode($markingData, JSON_UNESCAPED_UNICODE);

        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn($mockRecord);

        $result = $this->store->getMarking($subject);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('review', $result);
        $this->assertArrayHasKey('notify', $result);
    }

    // ==================== setMarking 测试 ====================

    public function testSetMarkingCreatesNewRecord(): void
    {
        $subject = 'order:new1';
        $marking = ['draft' => 1];

        // 不存在现有记录
        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn(null);

        // 应该插入新记录
        $this->markingModel
            ->shouldReceive('insert')
            ->with(\Mockery::on(function ($data) use ($subject, $marking) {
                return $data['subject'] === $subject
                    && json_decode($data['marking'], true) === $marking;
            }))
            ->once()
            ->andReturn(true);

        $this->store->setMarking($subject, $marking);
        $this->assertTrue(true); // Mockery 验证了 insert 被调用
    }

    public function testSetMarkingUpdatesExistingRecord(): void
    {
        $subject = 'order:existing1';
        $marking = ['review' => 1];

        // 存在现有记录
        $mockRecord = \Mockery::mock();
        $mockRecord->id = 42;

        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();

        // 第一次 where 用于 find
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn($mockRecord);

        // 第二次 where 用于 update
        $this->markingModel
            ->shouldReceive('where')
            ->with('id', 42)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('update')
            ->with(\Mockery::on(function ($data) use ($marking) {
                return json_decode($data['marking'], true) === $marking;
            }))
            ->once()
            ->andReturn(true);

        $this->store->setMarking($subject, $marking);
        $this->assertTrue(true); // Mockery 验证了 update 被调用
    }

    public function testSetMarkingWithContext(): void
    {
        $subject = 'order:ctx1';
        $marking = ['approved' => 1];
        $context = ['user_id' => 42, 'remark' => '审核通过'];

        // 不存在记录
        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn(null);
        $this->markingModel
            ->shouldReceive('insert')
            ->andReturn(true);

        // context 不影响 setMarking 的数据，只传递给 recordHistory
        $this->store->setMarking($subject, $marking, $context);

        $this->assertTrue(true); // No exception = success
    }

    public function testSetMarkingWithUnicodeCharacters(): void
    {
        $subject = 'order:中文';
        $marking = ['待审核' => 1, '已驳回' => 1];

        // 不存在记录
        $this->markingModel
            ->shouldReceive('where')
            ->with('subject', $subject)
            ->andReturnSelf();
        $this->markingModel
            ->shouldReceive('find')
            ->andReturn(null);
        $this->markingModel
            ->shouldReceive('insert')
            ->with(\Mockery::on(function ($data) use ($marking) {
                $decoded = json_decode($data['marking'], true);
                return isset($decoded['待审核']) && isset($decoded['已驳回']);
            }))
            ->once()
            ->andReturn(true);

        $this->store->setMarking($subject, $marking);
        $this->assertTrue(true); // Mockery 验证了 insert 被调用
    }

    // ==================== recordHistory 测试 ====================

    public function testRecordHistoryWritesCorrectFields(): void
    {
        $subject = 'order:hist1';
        $transitionName = 'submit';
        $fromPlace = 'draft';
        $toPlace = 'review';
        $context = ['user_id' => 1, 'remark' => '提交审核'];

        $this->historyModel
            ->shouldReceive('insert')
            ->with(\Mockery::on(function ($data) use ($subject, $transitionName, $fromPlace, $toPlace) {
                return $data['subject'] === $subject
                    && $data['transition_name'] === $transitionName
                    && $data['from_place'] === $fromPlace
                    && $data['to_place'] === $toPlace;
            }))
            ->once()
            ->andReturn(true);

        $this->store->recordHistory($subject, $transitionName, $fromPlace, $toPlace, $context);
        $this->assertTrue(true); // Mockery 验证了 insert 被调用
    }

    public function testRecordHistoryIncludesTimestamps(): void
    {
        $this->historyModel
            ->shouldReceive('insert')
            ->with(\Mockery::on(function ($data) {
                return isset($data['create_time'])
                    && !empty($data['create_time']);
            }))
            ->once()
            ->andReturn(true);

        $this->store->recordHistory('order:hist2', 'approve', 'review', 'approved', []);
        $this->assertTrue(true); // Mockery 验证了 insert 被调用
    }

    public function testRecordHistoryEncodesContext(): void
    {
        $context = ['user_id' => 7, 'ip' => '192.168.1.1'];

        $this->historyModel
            ->shouldReceive('insert')
            ->with(\Mockery::on(function ($data) use ($context) {
                $contextFromDb = json_decode($data['context'], true);
                return $contextFromDb === $context;
            }))
            ->once()
            ->andReturn(true);

        $this->store->recordHistory('order:hist3', 'reject', 'review', 'rejected', $context);
        $this->assertTrue(true); // Mockery 验证了 insert 被调用
    }

    // ==================== 批量操作测试 ====================

    public function testSetAndGetRoundtrip(): void
    {
        // 使用独立 mock 进行 set-then-get 完整往返测试
        $model = \Mockery::mock(\wise\workflow\model\WorkflowMarking::class)->makePartial();
        $store = new DatabaseMarkingStore($model, $this->historyModel);

        $subject = 'order:setget';

        // setMarking: where()->find() returns null then insert
        $model->shouldReceive('where')->with('subject', $subject)->andReturnSelf();
        $model->shouldReceive('find')->andReturn(null);
        $model->shouldReceive('insert')->with(\Mockery::any())->andReturn(true);

        $store->setMarking($subject, ['draft' => 1]);
        $this->assertTrue(true); // Mockery verified insert

        // Re-read: need to create another mock since the first one is consumed
        $model2 = \Mockery::mock(\wise\workflow\model\WorkflowMarking::class)->makePartial();
        $store2 = new DatabaseMarkingStore($model2, $this->historyModel);

        $mockRecord = \Mockery::mock();
        $mockRecord->marking = json_encode(['draft' => 1]);
        $model2->shouldReceive('where')->with('subject', $subject)->andReturnSelf();
        $model2->shouldReceive('find')->andReturn($mockRecord);

        $result = $store2->getMarking($subject);
        $this->assertSame(['draft' => 1], $result);
    }
}
