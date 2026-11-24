<?php
declare(strict_types=1);

namespace wise\workflow\mcp;

use wise\mcp\contract\ToolProviderInterface;
use wise\mcp\dto\ToolDefinition;
use wise\workflow\WorkflowRegistry;

/**
 * wise-workflow 的 MCP 工具提供者（只读）
 *
 * 把工作流定义以 MCP 工具暴露给外部 MCP 客户端（Claude Desktop / IDE 等），
 * 使其能够查询系统中的工作流与流转结构。
 *
 * 由 WorkflowServiceProvider::boot() 注册到 ToolProviderInterface::EXT_POINT；
 * 需安装 wiseadmin/wise-mcp 才会生效（否则类不可加载，注册被跳过）。
 *
 * 说明：执行器直接返回数组即可，ToolDefinition::call() 会自动 JSON 编码；
 * 失败时返回 ToolDefinition::error()，无需自定义 json()/error() 辅助方法。
 */
final class WorkflowToolProvider implements ToolProviderInterface
{
    public function getNamespace(): string
    {
        return 'workflow';
    }

    /**
     * @return ToolDefinition[]
     */
    public function listTools(): array
    {
        return [
            new ToolDefinition(
                name: 'workflow.list',
                description: '列出系统中所有工作流定义（名称、标题、描述、节点/迁移数量）',
                inputSchema: ['type' => 'object', 'properties' => []],
                handler: fn (array $args): array => $this->listWorkflows(),
                meta: ['source' => 'wise-workflow'],
            ),
            new ToolDefinition(
                name: 'workflow.definition',
                description: '获取指定工作流的详细定义（节点与迁移）',
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => '工作流名称（workflow.list 返回的 name）'],
                    ],
                    'required' => ['name'],
                ],
                handler: fn (array $args): array => $this->getDefinition((string) ($args['name'] ?? '')),
                meta: ['source' => 'wise-workflow'],
            ),
        ];
    }

    /**
     * @return array{workflows: array, count: int}|array{error: string}
     */
    private function listWorkflows(): array
    {
        try {
            WorkflowRegistry::loadFromDatabase();

            $items = [];
            foreach (WorkflowRegistry::all() as $workflow) {
                $meta    = $workflow->getMetadata();
                $items[] = [
                    'name'        => $workflow->getName(),
                    'title'       => (string) ($meta['title'] ?? ''),
                    'description' => (string) ($meta['description'] ?? ''),
                    'places'      => count($workflow->getPlaces()),
                    'transitions' => count($workflow->getTransitions()),
                ];
            }

            return ['workflows' => $items, 'count' => count($items)];
        } catch (\Throwable $e) {
            error_log('[wise-workflow] workflow.list 失败: ' . $e->getMessage());

            return ToolDefinition::error('工作流定义读取失败，请查看服务端日志');
        }
    }

    /**
     * @return array|array{error: string}
     */
    private function getDefinition(string $name): array
    {
        if ($name === '') {
            return ToolDefinition::error('缺少参数 name');
        }

        try {
            WorkflowRegistry::loadFromDatabase();
            if (!WorkflowRegistry::has($name)) {
                return ToolDefinition::error("工作流不存在: {$name}");
            }

            $workflow = WorkflowRegistry::get($name);

            $places = [];
            foreach ($workflow->getPlaces() as $place) {
                $places[] = method_exists($place, 'getName') ? (string) $place->getName() : (string) $place;
            }

            $transitions = [];
            foreach ($workflow->getTransitions() as $transition) {
                if (!method_exists($transition, 'getName')) {
                    $transitions[] = ['name' => (string) $transition];
                    continue;
                }
                $transitions[] = [
                    'name' => (string) $transition->getName(),
                    'from' => method_exists($transition, 'getFrom') ? $transition->getFrom() : [],
                    'to'   => method_exists($transition, 'getTo') ? $transition->getTo() : [],
                ];
            }

            return [
                'name'          => $workflow->getName(),
                'initial_place' => $workflow->getInitialPlace(),
                'places'        => $places,
                'transitions'   => $transitions,
            ];
        } catch (\Throwable $e) {
            error_log('[wise-workflow] workflow.definition 失败: ' . $e->getMessage());

            return ToolDefinition::error('工作流定义读取失败，请查看服务端日志');
        }
    }
}
