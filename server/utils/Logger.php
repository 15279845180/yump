<?php
/**
 * 操作日志工具
 *
 * 用法：
 *   Logger::log('create', 'customer', $account, ['name' => $name]);
 */

namespace VOS;

require_once __DIR__ . '/Database.php';

class Logger
{
    /**
     * 记录操作日志
     *
     * @param string $opType    操作类型: create/modify/delete/sync/login/pay/overdraft/cleanup
     * @param string $module    模块: customer/phone/gateway_mapping/gateway_routing/node/auth
     * @param string $target    操作目标（账户名、网关名等）
     * @param mixed  $detail    详细信息（数组或字符串）
     * @param int|null $userId  用户ID（不传则从JWT解析）
     * @param string $username  用户名（不传则从JWT解析）
     */
    public static function log($opType, $module, $target = '', $detail = null, $userId = null, $username = '')
    {
        try {
            // 如果没传 userId/username，尝试从 JWT 获取
            if ($userId === null) {
                $auth = \VOS\Auth::verify(); // verify 不抛异常，失败返回 false
                if ($auth) {
                    $userId = $auth['sub'] ?? null;
                    if ($username === '' ) $username = $auth['usr'] ?? ''; // JWT payload 用 'usr' 键
                }
            }

            $ip = self::getClientIp();
            $detailStr = is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : (string)$detail;

            $pdo = \Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO y_operation_logs (user_id, username, operation_type, module, target, ip_address, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $username,
                $opType,
                $module,
                mb_substr($target, 0, 200),
                $ip,
                mb_substr($detailStr, 0, 65535),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
            error_log('[Logger] 写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 构建人类可读的变更描述
     * @param array $old 旧数据（完整记录）
     * @param array $new 新数据（只包含变更的字段）
     * @param array $fieldLabels 字段名 → 中文标签映射
     * @return string 变更描述，如 "并发上限: 30 → 50, 优先级: 1 → 2"
     */
    public static function formatChanges($old, $new, $fieldLabels) {
        $changes = [];
        foreach ($new as $field => $newValue) {
            if ($field === 'name' || $field === 'account' || $field === 'e164') continue;
            if (!isset($fieldLabels[$field])) continue;
            $oldValue = $old[$field] ?? '';
            $oldStr = self::formatValue($field, $oldValue);
            $newStr = self::formatValue($field, $newValue);
            if ($oldStr !== $newStr) {
                $changes[] = $fieldLabels[$field] . ': ' . $oldStr . ' → ' . $newStr;
            }
        }
        return empty($changes) ? '' : implode(', ', $changes);
    }

    /**
     * 格式化字段值为人类可读文本
     */
    private static function formatValue($field, $value) {
        if ($value === null || $value === '') return '空';
        // 布尔/允许字段
        if (str_ends_with($field, '_allow') || str_ends_with($field, 'Allow')) {
            return (int)$value === 1 ? '允许' : '禁止';
        }
        // 并发上限
        if ($field === 'capacity') {
            $cap = (int)$value;
            if ($cap >= 2147483647 || $cap === -1) return '无限制';
            return (string)$cap;
        }
        // 锁定类型
        if ($field === 'lock_type' || $field === 'lockType') {
            return (int)$value === 1 ? '锁定' : '未锁定';
        }
        // 注册类型
        if ($field === 'register_type' || $field === 'registerType') {
            return (int)$value === 1 ? '动态' : '静态';
        }
        // 注销状态
        if ($field === 'canceled') {
            return (int)$value === 1 ? '已注销' : '正常';
        }
        // 节点/账户状态
        if ($field === 'status') {
            return (int)$value === 1 ? '启用' : '停用';
        }
        // RTP转发
        if ($field === 'rtp_forward_type' || $field === 'rtpForwardType') {
            return (int)$value === 1 ? '转发' : '不转发';
        }
        // 协议
        if ($field === 'protocol') {
            return (int)$value === 2 ? 'TCP' : 'UDP';
        }
        // 前缀方式
        if ($field === 'prefix_style' || $field === 'prefixStyle') {
            return (int)$value === 2 ? '去前缀' : '加前缀';
        }
        // 最低成本路由
        if ($field === 'least_cost_routing' || $field === 'leastCostRouting') {
            return (int)$value === 1 ? '开启' : '关闭';
        }
        // 费率限制
        if ($field === 'feerate_restrict' || $field === 'feerateRestrict') {
            return (int)$value === 1 ? '开启' : '关闭';
        }
        // 呼叫级别
        if ($field === 'call_level' || $field === 'callLevel') {
            $map = [0 => '国内', 1 => '国际', 2 => '本地', 3 => '特殊', 4 => '默认'];
            return $map[(int)$value] ?? (string)$value;
        }
        // 金额类字段保留3位小数
        if (in_array($field, ['money', 'limitMoney', 'limit_money', 'today_consumption', 'todayConsumption'])) {
            return number_format((float)$value, 3, '.', '');
        }
        return (string)$value;
    }

    /**
     * 获取客户端 IP
     */
    private static function getClientIp()
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
