<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\mcp\WorkflowToolProvider;
use wise\workflow\WorkflowRegistry;

/**
 * MCP 工具提供者单元测试
 *
 * 工作流列表来自数据库（测试环境为空），主要验证：
 * - 命名空间
 * - 工具列表结构与名称
 * - 工具调用（list / definition）含错误处理分支
 */
class WorkflowToolProviderTest extends TestCase
{
    private WorkflowToolProvider $provider;

    protected function setUp(): void
    {
        WorkflowRegistry::clear();
        $this->provider = new WorkflowToolProvider();
    }

    protected function tearDown(): void
    {
        WorkflowRegistry::clear();
    }

    public function testGetNamespace(): void
    {
        $this->assertSame('workflow', $this->provider->getNamespace());
    }

    public function testListToolsStructure(): void
    {
        $tools = $this->provider->listTools();
        $this->assertCount(2, $tools);
        $names = array_map(fn($t) => $t->name, $tools);
        $this->assertContains('workflow.list', $names);
        $this->assertContains('workflow.definition', $names);
    }

    public function testListWorkflowsReturnsStructuredResponse(): void
    {
        $tool = $this->findTool('workflow.list');
        $result = json_decode($tool->call([]), true);
        $this->assertIsArray($result);
        $this->assertTrue(isset($result['workflows']) || isset($result['error']));
        if (isset($result['workflows'])) {
            $this->assertSame(0, $result['count']);
        }
    }

    public function testGetDefinitionMissingNameReturnsError(): void
    {
        $tool = $this->findTool('workflow.definition');
        $result = json_decode($tool->call(['name' => '']), true);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetDefinitionUnknownWorkflowReturnsError(): void
    {
        $tool = $this->findTool('workflow.definition');
        $result = json_decode($tool->call(['name' => 'order']), true);
        $this->assertArrayHasKey('error', $result);
    }

    private function findTool(string $name)
    {
        foreach ($this->provider->listTools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }
        $this->fail("tool $name not found");
    }
}
