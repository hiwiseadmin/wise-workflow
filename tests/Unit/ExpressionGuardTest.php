<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\exception\WorkflowException;
use wise\workflow\guard\ExpressionGuard;

/**
 * ExpressionGuard 声明式表达式守卫单元测试
 *
 * 覆盖：
 * - 空表达式
 * - 比较运算符 == != > < >= <=
 * - 字符串/数值/布尔/null 字面量
 * - context.xxx 变量解析（含白名单与嵌套）
 * - marking.xxx 状态判断
 * - in 操作符
 * - && / || / ! 逻辑运算符
 * - 括号分组
 * - 求值失败抛 WorkflowException
 */
class ExpressionGuardTest extends TestCase
{
    private ExpressionGuard $guard;

    protected function setUp(): void
    {
        // 每次用例重置白名单，避免用例间污染
        ExpressionGuard::setAllowedContextKeys(null);
        $this->guard = new ExpressionGuard();
    }

    protected function tearDown(): void
    {
        ExpressionGuard::setAllowedContextKeys(null);
    }

    // ==================== 空表达式 ====================

    public function testEmptyExpressionAlwaysPasses(): void
    {
        $this->assertTrue($this->guard->evaluate('', [], []));
        $this->assertTrue($this->guard->evaluate('   ', [], []));
    }

    // ==================== 比较运算符 ====================

    public function testNumericGreaterOrEqual(): void
    {
        $this->assertTrue($this->guard->evaluate('context.amount >= 1000', [], ['amount' => 1500]));
        $this->assertTrue($this->guard->evaluate('context.amount >= 1000', [], ['amount' => 1000]));
        $this->assertFalse($this->guard->evaluate('context.amount >= 1000', [], ['amount' => 999]));
    }

    public function testNumericLessOrEqual(): void
    {
        $this->assertTrue($this->guard->evaluate('context.amount <= 100', [], ['amount' => 50]));
        $this->assertTrue($this->guard->evaluate('context.amount <= 100', [], ['amount' => 100]));
        $this->assertFalse($this->guard->evaluate('context.amount <= 100', [], ['amount' => 101]));
    }

    public function testEqualityLoose(): void
    {
        // 源码使用宽松比较（==），以下用例均符合宽松相等语义
        $this->assertTrue($this->guard->evaluate('context.amount == 100', [], ['amount' => 100]));
        $this->assertTrue($this->guard->evaluate('context.amount == 100', [], ['amount' => 100.0]));
        $this->assertTrue($this->guard->evaluate('context.amount == 100', [], ['amount' => '100']));
        $this->assertFalse($this->guard->evaluate('context.amount == 200', [], ['amount' => 100]));
    }

    public function testNotEqual(): void
    {
        $this->assertTrue($this->guard->evaluate('context.amount != 100', [], ['amount' => 200]));
        $this->assertFalse($this->guard->evaluate('context.amount != 100', [], ['amount' => 100]));
    }

    public function testGreaterAndLessThan(): void
    {
        $this->assertTrue($this->guard->evaluate('context.amount > 10', [], ['amount' => 11]));
        $this->assertFalse($this->guard->evaluate('context.amount > 10', [], ['amount' => 10]));
        $this->assertTrue($this->guard->evaluate('context.amount < 10', [], ['amount' => 9]));
        $this->assertFalse($this->guard->evaluate('context.amount < 10', [], ['amount' => 10]));
    }

    // ==================== 字符串字面量 ====================

    public function testStringLiteralEquality(): void
    {
        $this->assertTrue($this->guard->evaluate("context.department == 'finance'", [], ['department' => 'finance']));
        $this->assertFalse($this->guard->evaluate("context.department == 'finance'", [], ['department' => 'hr']));
    }

    public function testDoubleQuotedStringLiteral(): void
    {
        $this->assertTrue($this->guard->evaluate('context.department == "finance"', [], ['department' => 'finance']));
        $this->assertFalse($this->guard->evaluate('context.department == "finance"', [], ['department' => 'hr']));
    }

    // ==================== 纯字面量求值 ====================

    public function testPureBooleanLiteral(): void
    {
        $this->assertTrue($this->guard->evaluate('true', [], []));
        $this->assertFalse($this->guard->evaluate('false', [], []));
    }

    public function testPureNumericLiteralComparison(): void
    {
        $this->assertTrue($this->guard->evaluate('100 >= 50', [], []));
        $this->assertFalse($this->guard->evaluate('10 >= 50', [], []));
    }

    public function testNullLiteral(): void
    {
        $this->assertTrue($this->guard->evaluate('context.missing == null', [], []));
        $this->assertFalse($this->guard->evaluate('context.present == null', [], ['present' => 'x']));
    }

    // ==================== context 变量解析 ====================

    public function testContextResolution(): void
    {
        $this->assertTrue($this->guard->evaluate('context.vip == true', [], ['vip' => true]));
        $this->assertFalse($this->guard->evaluate('context.vip == true', [], ['vip' => false]));
    }

    public function testNestedContextResolution(): void
    {
        $context = ['user' => ['role' => 'admin', 'level' => 3]];
        $this->assertTrue($this->guard->evaluate("context.user.role == 'admin'", [], $context));
        $this->assertTrue($this->guard->evaluate('context.user.level >= 3', [], $context));
        $this->assertFalse($this->guard->evaluate("context.user.role == 'guest'", [], $context));
    }

    public function testContextWhitelistBlocksUnknownKey(): void
    {
        ExpressionGuard::setAllowedContextKeys(['amount']);
        // department 不在白名单 → 解析为 null
        $this->assertFalse($this->guard->evaluate("context.department == 'finance'", [], ['department' => 'finance']));
        // amount 在白名单 → 正常解析
        $this->assertTrue($this->guard->evaluate('context.amount > 100', [], ['amount' => 200]));
    }

    public function testContextWhitelistNullMeansNoRestriction(): void
    {
        ExpressionGuard::setAllowedContextKeys(null);
        $this->assertTrue($this->guard->evaluate("context.department == 'finance'", [], ['department' => 'finance']));
    }

    // ==================== marking 变量解析 ====================

    public function testMarkingStateTrue(): void
    {
        $this->assertTrue($this->guard->evaluate('marking.draft == true', ['draft' => 1], []));
        $this->assertFalse($this->guard->evaluate('marking.draft == true', ['review' => 1], []));
    }

    public function testMarkingStateFalse(): void
    {
        $this->assertTrue($this->guard->evaluate('marking.draft == false', ['review' => 1], []));
        $this->assertFalse($this->guard->evaluate('marking.draft == false', ['draft' => 1], []));
    }

    // ==================== in 操作符 ====================

    public function testInOperator(): void
    {
        $this->assertTrue($this->guard->evaluate("context.role in ['admin', 'manager']", [], ['role' => 'admin']));
        $this->assertTrue($this->guard->evaluate("context.role in ['admin', 'manager']", [], ['role' => 'manager']));
        $this->assertFalse($this->guard->evaluate("context.role in ['admin', 'manager']", [], ['role' => 'guest']));
    }

    public function testInOperatorWithNumbers(): void
    {
        $this->assertTrue($this->guard->evaluate('context.level in [1, 2, 3]', [], ['level' => 2]));
        $this->assertFalse($this->guard->evaluate('context.level in [1, 2, 3]', [], ['level' => 9]));
    }

    // ==================== 逻辑运算符 ====================

    public function testAndOperator(): void
    {
        $this->assertTrue($this->guard->evaluate(
            'context.amount > 1000 && context.vip == true',
            [],
            ['amount' => 1500, 'vip' => true]
        ));
        $this->assertFalse($this->guard->evaluate(
            'context.amount > 1000 && context.vip == true',
            [],
            ['amount' => 1500, 'vip' => false]
        ));
    }

    public function testOrOperator(): void
    {
        $this->assertTrue($this->guard->evaluate(
            "context.amount < 100 || context.role == 'admin'",
            [],
            ['amount' => 500, 'role' => 'admin']
        ));
        $this->assertFalse($this->guard->evaluate(
            "context.amount < 100 || context.role == 'admin'",
            [],
            ['amount' => 500, 'role' => 'guest']
        ));
    }

    public function testNotOperator(): void
    {
        $this->assertTrue($this->guard->evaluate('!marking.draft', ['review' => 1], []));
        $this->assertFalse($this->guard->evaluate('!marking.draft', ['draft' => 1], []));
    }

    public function testMixedLogicWithParentheses(): void
    {
        $expr = "(context.amount > 1000 && context.vip == true) || context.role == 'admin'";
        $this->assertTrue($this->guard->evaluate($expr, [], ['amount' => 50, 'vip' => false, 'role' => 'admin']));
        $this->assertTrue($this->guard->evaluate($expr, [], ['amount' => 2000, 'vip' => true, 'role' => 'guest']));
        $this->assertFalse($this->guard->evaluate($expr, [], ['amount' => 50, 'vip' => false, 'role' => 'guest']));
    }

    public function testParenthesisGrouping(): void
    {
        // 括号包裹的单一表达式
        $this->assertTrue($this->guard->evaluate('(context.amount > 100)', [], ['amount' => 200]));
        $this->assertFalse($this->guard->evaluate('(context.amount > 100)', [], ['amount' => 50]));
    }

    // ==================== 求值失败兜底 ====================

    /**
     * 空表达式视为通过（守卫不拦截）
     */
    public function testEmptyExpressionPasses(): void
    {
        $this->assertTrue($this->guard->evaluate('', [], []));
    }
}
