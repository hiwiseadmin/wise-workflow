<?php
declare(strict_types=1);

namespace wise\workflow\exception;

use think\exception\ValidateException;

class WorkflowException extends ValidateException
{
    protected $data;

    public function __construct(string $message, $data = [], int $code = 0)
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
