<?php
declare(strict_types=1);

namespace wise\workflow\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-workflow 业务错误码（号段 70000~79999）
 *
 * @package wise\workflow\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 通用工作流处理失败 */
    case WORKFLOW_ERROR = 70000;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::WORKFLOW_ERROR => '工作流处理失败',
        };
    }
}
