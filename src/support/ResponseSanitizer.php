<?php
declare(strict_types=1);

namespace wise\workflow\support;

/**
 * HTTP 响应字段裁剪（安全审计阶段 3 / E-2）
 *
 * 读端点此前会把内部策略与操作者信息原样回显：工作流定义里的 `guard_expression`
 * 与 `metadata.sla_config`、历史记录里的操作者 `ip` / `user_agent`。这些不是业务
 * 数据，而是防护配置与运维痕迹，对调用方没有价值却方便了攻击者。
 *
 * 裁剪在**控制器成功响应**这一处收口（`WorkflowController::success()`），
 * 因此后续新增的读端点自动受保护。
 *
 * 配置（`config/wise-workflow.php`）：
 * - `response.sensitive_fields`：命中键名的字段递归剔除（默认见下）
 * - `response.reveal_sensitive_fields`：置 true 则完全不裁剪（旧行为，不推荐；
 *   如需「按角色放行」，请在自己的中间件里按请求覆写该配置）
 *
 * @package wise\workflow\support
 */
class ResponseSanitizer
{
    /**
     * 默认裁剪的键名
     */
    public const DEFAULT_SENSITIVE_FIELDS = [
        'guard_expression',
        'sla_config',
        'ip',
        'user_agent',
    ];

    /**
     * 按配置裁剪响应数据（不修改入参）
     *
     * @param mixed $data
     * @return mixed
     */
    public static function sanitize($data)
    {
        $fields = self::sensitiveFields();

        if ($fields === [] || !is_array($data)) {
            return $data;
        }

        return self::strip($data, array_flip($fields));
    }

    /**
     * 当前生效的裁剪字段列表（空数组表示不裁剪）
     */
    public static function sensitiveFields(): array
    {
        try {
            if ((bool) config('wise-workflow.response.reveal_sensitive_fields', false)) {
                return [];
            }

            $configured = config('wise-workflow.response.sensitive_fields', null);
            if ($configured === null) {
                return self::DEFAULT_SENSITIVE_FIELDS;
            }

            return array_values(array_map('strval', (array) $configured));
        } catch (\Throwable $e) {
            return self::DEFAULT_SENSITIVE_FIELDS;
        }
    }

    /**
     * 递归剔除命中键名的字段
     */
    protected static function strip(array $data, array $keys): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && isset($keys[$key])) {
                continue;
            }
            $result[$key] = is_array($value) ? self::strip($value, $keys) : $value;
        }

        return $result;
    }
}
