<?php
declare(strict_types=1);

namespace wise\workflow\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\workflow\SlaManager;
use wise\workflow\WorkflowRegistry;

/**
 * SLA 超时检查命令
 * 
 * 使用方式：
 *   php think wise:workflow:sla-check
 *   php think wise:workflow:sla-check --workflow=order
 * 
 * 建议配置为定时任务（每 5 分钟）：
 *   每5分钟执行一次: php /path/to/think wise:workflow:sla-check
 */
class SlaCheck extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:workflow:sla-check')
            ->setDescription('检查工作流 SLA 超时实例')
            ->addOption('workflow', 'w', Option::VALUE_OPTIONAL, '指定工作流名称（可选）', null)
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅检测不执行动作');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('<info>========================================</info>');
        $output->writeln('<info>WiseWorkflow SLA Check</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');

        // 加载工作流定义
        WorkflowRegistry::clear();
        WorkflowRegistry::loadFromDatabase();

        $workflowName = $input->getOption('workflow');
        $dryRun       = $input->getOption('dry-run');

        $slaManager = new SlaManager();

        // 如果安装了通知集成，注入通知器
        if (class_exists('\wise\notification\Notification')) {
            $slaManager = new SlaManager(null, null, function ($message, $data) use ($output) {
                $output->writeln("  <comment>[Notify]</comment> {$message}");
            });
        }

        if ($workflowName) {
            if (!WorkflowRegistry::has($workflowName)) {
                $output->writeln("<error>工作流 [{$workflowName}] 不存在</error>");
                return 1;
            }
            $workflow = WorkflowRegistry::get($workflowName);
            $results = $slaManager->checkWorkflow($workflow);
        } else {
            $results = $slaManager->checkAll();
        }

        if (empty($results)) {
            $output->writeln('<info>没有超时的工作流实例。</info>');
            return 0;
        }

        $output->writeln("<comment>发现 " . count($results) . " 个超时实例：</comment>");
        $output->writeln('');

        foreach ($results as $result) {
            $output->writeln(sprintf(
                "  <warning>[%s]</warning> %-30s | 状态: %-15s | 超时: %d秒 | 动作: %s",
                strtoupper($result['action']),
                $result['subject'],
                $result['place'],
                $result['elapsed'],
                $result['action']
            ));
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<comment>[DRY RUN] 未执行实际操作。</comment>');
        }

        $output->writeln('');
        $output->writeln('<info>SLA 检查完成。</info>');
        return 0;
    }
}
