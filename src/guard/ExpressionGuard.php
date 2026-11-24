<?php
declare(strict_types=1);

namespace wise\workflow\guard;

use wise\workflow\exception\WorkflowException;

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
     * context 变量访问白名单（防止敏感信息泄露）
     * 仅允许通过表达式访问这些顶层 key，其他 key 返回 null。
     * null 表示不限制（向后兼容）。
     * @var array<string>|null
     */
    protected static $allowedContextKeys = null;

    /**
     * 严格模式：表达式出现无法识别的标识符时直接报错
     *
     * 默认 true。此前未识别标识符会被 resolveValue 原样返回字符串，
     * 经 (bool) 判定后**恒为真**——表达式里漏写 `context.` 前缀即等于条件永远通过
     * （安全审计阶段 1 / B-3）。严格模式下改为显式报错，杜绝这类 fail-open。
     *
     * @var bool
     */
    protected bool $strict = true;

    public function __construct()
    {
        try {
            $this->strict = (bool) config('wise-workflow.expression.strict', true);
        } catch (\Throwable $e) {
            $this->strict = true;
        }
    }

    /**
     * 设置严格模式（测试或业务按需覆盖）
     */
    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;
    }

    /**
     * 设置 context 访问白名单
     * @param array<string>|null $keys  null 表示不限制
     */
    public static function setAllowedContextKeys(?array $keys): void
    {
        static::$allowedContextKeys = $keys;
    }

    /**
     * 求值表达式
     *
     * @param string $expression 表达式字符串
     * @param array  $marking    当前标记
     * @param array  $context    上下文
     * @return bool 求值结果
     * @throws WorkflowException 表达式语法错误时
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
            throw new WorkflowException(
                '守卫表达式求值失败，请检查表达式配置',
                ['expression' => $expression, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * 递归求值表达式
     */
    protected function evaluateExpression(string $expression, array $marking, array $context): bool
    {
        $expression = trim($expression);

        $orParts = $this->splitByOperator($expression, '||');
        if (count($orParts) > 1) {
            foreach ($orParts as $part) {
                if ($this->evaluateExpression($part, $marking, $context)) {
                    return true;
                }
            }
            return false;
        }

        $andParts = $this->splitByOperator($expression, '&&');
        if (count($andParts) > 1) {
            foreach ($andParts as $part) {
                if (!$this->evaluateExpression($part, $marking, $context)) {
                    return false;
                }
            }
            return true;
        }

        if (strlen($expression) > 0 && $expression[0] === '(' && $this->isWrappedByParens($expression)) {
            return $this->evaluateExpression(substr($expression, 1, -1), $marking, $context);
        }

        if (substr($expression, 0, 1) === '!') {
            return !$this->evaluateExpression(substr($expression, 1), $marking, $context);
        }

        if (preg_match('/^(.+?)\s+in\s+\[(.+)\]$/i', $expression, $m)) {
            $left  = trim($m[1]);
            $items = array_map('trim', explode(',', $m[2]));
            $items = array_map(function ($v) {
                return $this->parseLiteral(trim($v));
            }, $items);
            return in_array($this->resolveValue($left, $marking, $context), $items, true);
        }

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

        $val = $this->resolveValue($expression, $marking, $context);

        if (is_string($val) && $val === $expression) {
            if ($this->strict) {
                throw new \RuntimeException(
                    "未知标识符 [{$expression}]：请检查是否漏写 context. / marking. 前缀，或该键不在白名单内"
                );
            }
            return false;
        }

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
    protected function findOperatorPosition(string $expression, string $operator): int|false
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
        return false;
    }

    /**
     * 检查表达式是否由一对匹配的外层括号包裹
     *
     * 避免了简单首尾字符检查导致的复合表达式误剥离问题。
     * 例如 (a == 1) && (b == 2) 不应剥离外层括号。
     */
    protected function isWrappedByParens(string $expression): bool
    {
        $depth = 0;
        $len   = strlen($expression);
        for ($i = 0; $i < $len - 1; $i++) {
            if ($expression[$i] === '(') $depth++;
            if ($expression[$i] === ')') $depth--;
            if ($depth === 0) return false;
        }
        return $depth === 1 && $expression[$len - 1] === ')';
    }

    /**
     * 解析变量引用为实际值
     */
    protected function resolveValue(string $expr, array $marking, array $context)
    {
        $expr = trim($expr);

        if (strtolower($expr) === 'true') {
            return true;
        }
        if (strtolower($expr) === 'false') {
            return false;
        }
        if (strtolower($expr) === 'null') {
            return null;
        }

        if ((strpos($expr, '"') === 0 && strrpos($expr, '"') === strlen($expr) - 1) ||
            (strpos($expr, "'") === 0 && strrpos($expr, "'") === strlen($expr) - 1 && strlen($expr) >= 2)) {
            return substr($expr, 1, -1);
        }

        if (is_numeric($expr)) {
            return strpos($expr, '.') !== false ? (float)$expr : (int)$expr;
        }

        if (strpos($expr, 'context.') === 0) {
            $key = substr($expr, 8);
            if (static::$allowedContextKeys !== null) {
                $topKey = explode('.', $key)[0];
                if (!in_array($topKey, static::$allowedContextKeys, true)) {
                    try {
                        \wise\support\context\ContextualLogger::warning(
                            "[wise-workflow] 守卫读取了不在白名单中的 context 键 [{$topKey}]，按 null（假）处理",
                            ['workflow', 'expression']
                        );
                    } catch (\Throwable $e) {
                    }
                    return null;
                }
            }
            return $this->getNestedValue($context, $key);
        }

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
