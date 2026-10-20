<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\model\Transition;

/**
 * Transition 转换规则单元测试
 *
 * 覆盖：
 * - 构造函数（单个/多个 from/to）
 * - getName()、getFrom()、getTo() 方法
 * - getGuard()、setGuard() 守卫条件
 * - 边界情况
 */
class TransitionTest extends TestCase
{
    // ==================== 构造函数测试（单个 from/to）====================

    public function testConstructWithStringFromAndTo(): void
    {
        $transition = new Transition('submit', 'draft', 'review');

        $this->assertSame('submit', $transition->getName());
        $this->assertSame(['draft'], $transition->getFrom());
        $this->assertSame(['review'], $transition->getTo());
        $this->assertNull($transition->getGuard());
    }

    public function testConstructWithNoGuard(): void
    {
        $transition = new Transition('approve', 'review', 'approved');

        $this->assertSame('approve', $transition->getName());
        $this->assertNull($transition->getGuard());
    }

    // ==================== 构造函数测试（多个 from/to）====================

    public function testConstructWithArrayFrom(): void
    {
        $transition = new Transition('merge', ['draft', 'review'], 'approved');

        $this->assertSame(['draft', 'review'], $transition->getFrom());
        $this->assertSame(['approved'], $transition->getTo());
    }

    public function testConstructWithArrayTo(): void
    {
        $transition = new Transition('split', 'draft', ['review', 'approved']);

        $this->assertSame(['draft'], $transition->getFrom());
        $this->assertSame(['review', 'approved'], $transition->getTo());
    }

    public function testConstructWithArrayFromAndArrayTo(): void
    {
        $transition = new Transition(
            'complex_transition',
            ['draft', 'pending'],
            ['review', 'approved']
        );

        $this->assertSame(['draft', 'pending'], $transition->getFrom());
        $this->assertSame(['review', 'approved'], $transition->getTo());
    }

    // ==================== 守卫条件测试 ====================

    public function testConstructWithGuard(): void
    {
        $guard = function (array $marking, array $context): bool {
            return true;
        };

        $transition = new Transition('submit', 'draft', 'review', $guard);

        $this->assertSame($guard, $transition->getGuard());
    }

    public function testGuardReturnsTrue(): void
    {
        $guard = function (array $marking, array $context): bool {
            return true;
        };

        $transition = new Transition('submit', 'draft', 'review', $guard);
        $guardCallback = $transition->getGuard();

        $this->assertIsCallable($guardCallback);
        $this->assertTrue($guardCallback(['draft' => 1], []));
    }

    public function testGuardReturnsFalse(): void
    {
        $guard = function (array $marking, array $context): bool {
            return false;
        };

        $transition = new Transition('submit', 'draft', 'review', $guard);
        $guardCallback = $transition->getGuard();

        $this->assertFalse($guardCallback(['draft' => 1], []));
    }

    public function testGuardWithMarkingCheck(): void
    {
        $guard = function (array $marking, array $context): bool {
            return isset($marking['draft']) && !empty($context['user_id']);
        };

        $transition = new Transition('submit', 'draft', 'review', $guard);
        $guardCallback = $transition->getGuard();

        $this->assertTrue($guardCallback(['draft' => 1], ['user_id' => 1]));
        $this->assertFalse($guardCallback(['draft' => 1], []));
        $this->assertFalse($guardCallback(['review' => 1], ['user_id' => 1]));
    }

    // ==================== setGuard 测试 ====================

    public function testSetGuard(): void
    {
        $transition = new Transition('submit', 'draft', 'review');
        $this->assertNull($transition->getGuard());

        $guard = function (array $marking, array $context): bool {
            return isset($marking['draft']);
        };

        $transition->setGuard($guard);
        $this->assertSame($guard, $transition->getGuard());
    }

    public function testSetGuardToNull(): void
    {
        $guard = function (array $marking, array $context): bool {
            return true;
        };

        $transition = new Transition('submit', 'draft', 'review', $guard);
        $this->assertNotNull($transition->getGuard());

        $transition->setGuard(null);
        $this->assertNull($transition->getGuard());
    }

    public function testOverrideGuard(): void
    {
        $guard1 = function (array $marking, array $context): bool {
            return true;
        };
        $guard2 = function (array $marking, array $context): bool {
            return false;
        };

        $transition = new Transition('submit', 'draft', 'review', $guard1);
        $this->assertSame($guard1, $transition->getGuard());

        $transition->setGuard($guard2);
        $this->assertSame($guard2, $transition->getGuard());
    }

    // ==================== 边界情况测试 ====================

    public function testEmptyFromArray(): void
    {
        $transition = new Transition('jump', [], 'review');
        $this->assertSame([], $transition->getFrom());
    }

    public function testEmptyToArray(): void
    {
        $transition = new Transition('destroy', 'draft', []);
        $this->assertSame([], $transition->getTo());
    }

    public function testChineseTransitionName(): void
    {
        $transition = new Transition('提交审批', '草稿', '审核中');
        $this->assertSame('提交审批', $transition->getName());
        $this->assertSame(['草稿'], $transition->getFrom());
        $this->assertSame(['审核中'], $transition->getTo());
    }
}
