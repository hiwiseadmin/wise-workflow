<?php
declare(strict_types=1);

namespace wise\workflow;

/**
 * 声明式条件表达式守卫（Expression Guard）
 * 
 * 支持在数据库定义的工作流中使用简单表达式语法作为守卫条件
 * 
 * 语法规则：
 * - context.key      → 访问上下文中的值
 * - marking.key      → 检查是否处于某状态（true/false）
 * - == != > < >= <=  → 比较运算符
 * - && || !          → 逻辑运算符
 * - 'string'         → 字符串字面量
 * - null             → null 值
 * - in [a, b]        → 包含判断
 * 
 * 示例：
 * - "context.amount >= 1000"
 * - "context.department == 'finance'"
 * - "marking.draft == true"
 * - "context.role in ['admin', 'manager']"
 * - "context.amount > 1000 && context.vip == true"
 * 
 * @package wise\workflow
 */
class ExpressionGuard
{
    /**
     * 求值表达式
     * 
     * @param string $expression 表达式字符串
     * @param array  $marking    当前标记
     * @param array  $context    上下文
     * @return bool 求值结果
     * @throws exception\WorkflowException 表达式语法错误时
     */
    public function evaluate(string $expression, array $marking, array $context): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return true;
        }

        try {
            return $this->evaluateExpression($expression, $marking, $context);
        } catch (\Throwable $e) {
            throw new exception\WorkflowException(
                "表达式求值失败: [{$expression}] - {$e->getMessage()}",
                ['expression' => $expression]
            );
        }
    }

    /**
     * 递归求值表达式
     */
    protected function evaluateExpression(string $expression, array $marking, array $context): bool
    {
        $expression = trim($expression);

        // 处理 OR (优先级最低)
        $orParts = $this->splitByOperator($expression, '||');
        if (count($orParts) > 1) {
            foreach ($orParts as $part) {
                if ($this->evaluateExpression($part, $marking, $context)) {
                    return true;
                }
            }
            return false;
        }

        // 处理 AND
        $andParts = $this->splitByOperator($expression, '&&');
        if (count($andParts) > 1) {
            foreach ($andParts as $part) {
                if (!$this->evaluateExpression($part, $marking, $context)) {
                    return false;
                }
            }
            return true;
        }

        // 处理 NOT
        if (substr($expression, 0, 1) === '!') {
            return !$this->evaluateExpression(substr($expression, 1), $marking, $context);
        }

        // 去除外层括号
        if ($expression[0] === '(' && $expression[strlen($expression) - 1] === ')') {
            return $this->evaluateExpression(substr($expression, 1, -1), $marking, $context);
        }

        // 处理 IN 操作符
        if (preg_match('/^(.+?)\s+in\s+\[(.+)\]$/i', $expression, $m)) {
            $left  = trim($m[1]);
            $items = array_map('trim', explode(',', $m[2]));
            $items = array_map(function ($v) {
                return $this->parseLiteral(trim($v));
            }, $items);
            return in_array($this->resolveValue($left, $marking, $context), $items, true);
        }

        // 处理比较运算符
        $operators = ['>=', '<=', '!=', '==', '>', '<'];
        foreach ($operators as $op) {
            $pos = $this->findOperatorPosition($expression, $op);
            if ($pos !== false) {
                $left  = trim(substr($expression, 0, $pos));
                $right = trim(substr($expression, $pos + strlen($op)));
                $leftVal  = $this->resolveValue($left, $marking, $context);
                $rightVal = $this->resolveValue($right, $marking, $context);

                switch ($op) {
                    case '==': return $leftVal == $rightVal;
                    case '!=': return $leftVal != $rightVal;
                    case '>':  return $leftVal > $rightVal;
                    case '<':  return $leftVal < $rightVal;
                    case '>=': return $leftVal >= $rightVal;
                    case '<=': return $leftVal <= $rightVal;
                }
            }
        }

        // 纯值求值（单独的值，truthy判断）
        $val = $this->resolveValue($expression, $marking, $context);
        return (bool)$val;
    }

    /**
     * 按操作符分割表达式（不分割引号内和括号内的部分）
     */
    protected function splitByOperator(string $expression, string $operator): array
    {
        $parts  = [];
        $depth  = 0;
        $inStr  = false;
        $strChar = '';
        $current = '';
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expression[$i];

            if (!$inStr && ($ch === '(' || $ch === '[')) {
                $depth++;
            } elseif (!$inStr && ($ch === ')' || $ch === ']')) {
                $depth--;
            } elseif (($ch === '"' || $ch === "'") && ($i === 0 || $expression[$i-1] !== '\\')) {
                if (!$inStr) {
                    $inStr = true;
                    $strChar = $ch;
                } elseif ($ch === $strChar) {
                    $inStr = false;
                }
            }

            if ($depth === 0 && !$inStr && substr($expression, $i, strlen($operator)) === $operator) {
                $parts[] = trim($current);
                $current = '';
                $i += strlen($operator) - 1;
            } else {
                $current .= $ch;
            }
        }
        $parts[] = trim($current);
        return $parts;
    }

    /**
     * 查找操作符在表达式中的位置（跳过引号和括号）
     */
    protected function findOperatorPosition(string $expression, string $operator): ?int
    {
        $depth  = 0;
        $inStr  = false;
        $strChar = '';
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expression[$i];

            if (!$inStr && ($ch === '(' || $ch === '[')) {
                $depth++;
            } elseif (!$inStr && ($ch === ')' || $ch === ']')) {
                $depth--;
            } elseif (($ch === '"' || $ch === "'") && ($i === 0 || $expression[$i-1] !== '\\')) {
                if (!$inStr) {
                    $inStr = true;
                    $strChar = $ch;
                } elseif ($ch === $strChar) {
                    $inStr = false;
                }
            }

            if ($depth === 0 && !$inStr && substr($expression, $i, strlen($operator)) === $operator) {
                return $i;
            }
        }
        return null;
    }

    /**
     * 解析变量引用为实际值
     */
    protected function resolveValue(string $expr, array $marking, array $context)
    {
        $expr = trim($expr);

        // 布尔字面量
        if (strtolower($expr) === 'true') {
            return true;
        }
        if (strtolower($expr) === 'false') {
            return false;
        }
        if (strtolower($expr) === 'null') {
            return null;
        }

        // 字符串字面量
        if ((strpos($expr, '"') === 0 && strrpos($expr, '"') === strlen($expr) - 1) ||
            (strpos($expr, "'") === 0 && strrpos($expr, "'") === strlen($expr) - 1 && strlen($expr) >= 2)) {
            return substr($expr, 1, -1);
        }

        // 数值字面量
        if (is_numeric($expr)) {
            return strpos($expr, '.') !== false ? (float)$expr : (int)$expr;
        }

        // context.xxx 变量
        if (strpos($expr, 'context.') === 0) {
            $key = substr($expr, 8);
            return $this->getNestedValue($context, $key);
        }

        // marking.xxx 变量
        if (strpos($expr, 'marking.') === 0) {
            $key = substr($expr, 8);
            return isset($marking[$key]);
        }

        return $expr;
    }

    /**
     * 解析字面量
     */
    protected function parseLiteral(string $value)
    {
        $value = trim($value);
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        if (strtolower($value) === 'null') return null;
        if (is_numeric($value)) return strpos($value, '.') !== false ? (float)$value : (int)$value;
        $value = trim($value, "'\"");
        return $value;
    }

    /**
     * 获取嵌套数组值（支持点号分隔的键名）
     */
    protected function getNestedValue(array $data, string $key)
    {
        $keys = explode('.', $key);
        $value = $data;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }
        return $value;
    }
}
