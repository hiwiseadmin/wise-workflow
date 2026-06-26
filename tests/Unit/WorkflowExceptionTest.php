<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\exception\WorkflowException;

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
        $this->assertSame(0, $exception->getCode());
        $this->assertSame([], $exception->getData());
    }

    public function testConstructWithMessageAndData(): void
    {
        $data = ['key' => 'value', 'transition' => 'submit'];
        $exception = new WorkflowException('Test error', $data);

        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame($data, $exception->getData());
        $this->assertSame(0, $exception->getCode());
    }

    public function testConstructWithMessageAndCode(): void
    {
        $exception = new WorkflowException('Test error', [], 400);

        $this->assertSame('Test error', $exception->getMessage());
        $this->assertSame(400, $exception->getCode());
    }

    public function testConstructWithAllParameters(): void
    {
        $data = ['workflow' => 'order', 'transition' => 'submit'];
        $exception = new WorkflowException('Workflow error', $data, 500);

        $this->assertSame('Workflow error', $exception->getMessage());
        $this->assertSame($data, $exception->getData());
        $this->assertSame(500, $exception->getCode());
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

    public function testWorkflowExceptionExtendsValidateException(): void
    {
        $exception = new WorkflowException('Test');
        $this->assertInstanceOf(\think\exception\ValidateException::class, $exception);
    }
}
