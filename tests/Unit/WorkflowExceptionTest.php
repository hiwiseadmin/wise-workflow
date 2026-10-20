<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\exception\WorkflowException;
use wise\support\exception\WiseException;

/**
 * WorkflowException 异常类单元测试
 *
 * 覆盖：
 * - 构造函数（带/不带 data 和 code）
 * - getData() 方法
 * - 继承关系验证
 */
class WorkflowExceptionTest extends TestCase
{
    // ==================== 基本构造测试 ====================

    public function testConstructWithMessageOnly(): void
    {
        $exception = new WorkflowException('Test error message');

        $this->assertSame('Test error message', $exception->getMessage());
        $this->assertSame(70000, $exception->getErrorCode());
        $this->assertSame(400, $exception->getHttpCode());
        $this->assertSame([], $exception->getData());
    }

    public function testConstructWithMessageAndData(): void
    {
        $data = ['key' => 'value', 'transition' => 'submit'];
        $exception = new WorkflowException('Test error', $data);

        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame($data, $exception->getData());
        $this->assertSame(70000, $exception->getErrorCode());
    }

    public function testConstructWithMessageAndCode(): void
    {
        $exception = new WorkflowException('Test error', [], 400);

        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame(400, $exception->getHttpCode());
    }

    public function testConstructWithAllParameters(): void
    {
        $data = ['workflow' => 'order', 'transition' => 'submit'];
        $exception = new WorkflowException('Workflow error', $data, 500);

        $this->assertSame('Workflow error', $exception->getMessage());
        $this->assertSame($data, $exception->getData());
        $this->assertSame(500, $exception->getHttpCode());
    }

    // ==================== getData 测试 ====================

    public function testGetDataReturnsArray(): void
    {
        $exception = new WorkflowException('Error', ['foo' => 'bar']);
        $this->assertIsArray($exception->getData());
        $this->assertArrayHasKey('foo', $exception->getData());
        $this->assertSame('bar', $exception->getData()['foo']);
    }

    public function testGetDataReturnsEmptyArrayWhenNoData(): void
    {
        $exception = new WorkflowException('Error');
        $this->assertSame([], $exception->getData());
    }

    public function testGetDataReturnsEmptyArray(): void
    {
        $exception = new WorkflowException('Error', []);
        $this->assertIsArray($exception->getData());
        $this->assertEmpty($exception->getData());
    }

    // ==================== 继承关系测试 ====================

    public function testWorkflowExceptionIsThrowable(): void
    {
        $exception = new WorkflowException('Test');
        $this->assertInstanceOf(\Throwable::class, $exception);
    }

    public function testWorkflowExceptionExtendsWiseException(): void
    {
        $exception = new WorkflowException('Test');
        $this->assertInstanceOf(WiseException::class, $exception);
    }
}
