<?php
declare(strict_types=1);

namespace wise\workflow\exception;

use wise\support\exception\WiseException;

/**
 * 工作流异常
 *
 * 错误码：ErrorCode::WORKFLOW_ERROR (70000)
 * HTTP：默认 400（历史 $code 参数保留为 HTTP 码覆盖语义）
 */
class WorkflowException extends WiseException
{
    /**
     * @param string          $message  异常消息
     * @param array           $data     附加数据（上下文）
     * @param int             $code     HTTP 状态码覆盖（0=默认 400）
     * @param \Throwable|null $previous 前置异常
     */
    public function __construct(string $message = '', array $data = [], int $code = 0, ?\Throwable $previous = null)
    {
        $httpCode = $code > 0 ? $code : 400;
        parent::__construct(ErrorCode::WORKFLOW_ERROR->value, $message, $httpCode, $previous, $data);
    }

    /**
     * 获取附加数据（上下文）
     */
    public function getData(): mixed
    {
        return $this->getContext();
    }
}
