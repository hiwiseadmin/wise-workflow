<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\exception\ErrorCode;
use wise\support\exception\ErrorCodeInterface;

/**
 * 错误码枚举单元测试
 */
class ErrorCodeTest extends TestCase
{
    public function testErrorCodeImplementsInterface(): void
    {
        $this->assertInstanceOf(ErrorCodeInterface::class, ErrorCode::WORKFLOW_ERROR);
    }

    public function testWorkflowErrorValueAndMessage(): void
    {
        $this->assertSame(70000, ErrorCode::WORKFLOW_ERROR->value);
        $this->assertSame('工作流处理失败', ErrorCode::WORKFLOW_ERROR->defaultMessage());
    }

    public function testIsBackedIntEnum(): void
    {
        $this->assertTrue(enum_exists(ErrorCode::class));
        $cases = ErrorCode::cases();
        $this->assertCount(1, $cases);
        $this->assertSame(ErrorCode::WORKFLOW_ERROR, $cases[0]);
    }
}
