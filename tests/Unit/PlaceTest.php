<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\Place;

/**
 * Place 状态节点单元测试
 *
 * 覆盖：
 * - 构造函数及名称存取
 * - getName() 方法
 * - __toString() 魔术方法
 * - 边界情况（空字符串、特殊字符、长字符串）
 */
class PlaceTest extends TestCase
{
    // ==================== 基础功能测试 ====================

    public function testConstructAndGetName(): void
    {
        $place = new Place('draft');
        $this->assertSame('draft', $place->getName());
    }

    public function testConstructWithDifferentNames(): void
    {
        $testCases = [
            'review',
            'approved',
            'rejected',
            'pending_payment',
            'shipped',
            'delivered',
            'cancelled',
            'refunded',
        ];

        foreach ($testCases as $name) {
            $place = new Place($name);
            $this->assertSame($name, $place->getName());
        }
    }

    // ==================== __toString 测试 ====================

    public function testToStringReturnsName(): void
    {
        $place = new Place('draft');
        $this->assertSame('draft', (string) $place);
    }

    public function testToStringMatchesGetName(): void
    {
        $names = ['draft', 'review', 'approved', 'archived'];
        foreach ($names as $name) {
            $place = new Place($name);
            $this->assertSame($place->getName(), (string) $place);
        }
    }

    // ==================== 边界情况测试 ====================

    public function testEmptyStringName(): void
    {
        $place = new Place('');
        $this->assertSame('', $place->getName());
        $this->assertSame('', (string) $place);
    }

    public function testNumericName(): void
    {
        $place = new Place('123');
        $this->assertSame('123', $place->getName());
    }

    public function testSpecialCharactersInName(): void
    {
        $place = new Place('status:pending');
        $this->assertSame('status:pending', $place->getName());
    }

    public function testChineseCharactersInName(): void
    {
        $place = new Place('待审核');
        $this->assertSame('待审核', $place->getName());
    }

    public function testLongStringName(): void
    {
        $longName = str_repeat('a', 1000);
        $place = new Place($longName);
        $this->assertSame($longName, $place->getName());
    }

    // ==================== 独立性测试 ====================

    public function testPlaceInstancesAreIndependent(): void
    {
        $place1 = new Place('draft');
        $place2 = new Place('draft');

        $this->assertNotSame($place1, $place2);
        $this->assertEquals($place1->getName(), $place2->getName());
    }

    public function testMultiplePlacesWithDifferentNames(): void
    {
        $places = [
            new Place('draft'),
            new Place('review'),
            new Place('approved'),
        ];

        $this->assertCount(3, $places);
        $this->assertSame('draft', $places[0]->getName());
        $this->assertSame('review', $places[1]->getName());
        $this->assertSame('approved', $places[2]->getName());
    }
}
