<?php

namespace VOS;

/**
 * JWT 认证工具类
 *
 * 签发 / 校验 JSON Web Token（HMAC-SHA256）
 * 密钥从环境变量 YUMP_JWT_SECRET 读取，未设置时抛异常
 *
 * 扩展预留：
 *   - payload 中已携带 role 字段，后续可直接用于角色/权限判断
 *   - verify() 返回完整 payload，调用方可取 sub/usr/role
 */
class Auth
{
    /** @var string|null */
    private static $secret = null;

    /** Token 有效期（秒），默认 24 小时 */
    const TTL = 86400;

    // ─────────────────────────── 密钥 ───────────────────────────

    private static function getSecret(): string
    {
        if (self::$secret === null) {
            require_once __DIR__ . '/Env.php';
            self::$secret = \Env::get('YUMP_JWT_SECRET');
            if (self::$secret === '') {
                throw new \RuntimeException('环境变量 YUMP_JWT_SECRET 未设置，请检查 server/.env 文件');
            }
        }
        return self::$secret;
    }

    // ─────────────────────────── 签发 ───────────────────────────

    /**
     * 生成 JWT
     *
     * @param int    $userId   用户 ID
     * @param string $username 登录名
     * @param string $role     角色标识（预留，当前固定 'admin'）
     */
    public static function generateToken(int $userId, string $username, string $role): string
    {
        $header  = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64url(json_encode([
            'sub'  => $userId,
            'usr'  => $username,
            'role' => $role,
            'iat'  => time(),
            'exp'  => time() + self::TTL,
        ]));
        $signature = self::base64url(
            hash_hmac('sha256', "$header.$payload", self::getSecret(), true)
        );
        return "$header.$payload.$signature";
    }

    // ─────────────────────────── 校验 ───────────────────────────

    /**
     * 从当前请求中提取并校验 JWT
     *
     * @return array|false  成功返回 payload 数组（含 sub/usr/role/iat/exp），失败返回 false
     */
    public static function verify()
    {
        $token = self::extractToken();
        if ($token === '') {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$header, $payload, $signature] = $parts;

        // 校验签名
        $expected = self::base64url(
            hash_hmac('sha256', "$header.$payload", self::getSecret(), true)
        );
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // 解析 payload
        $data = json_decode(self::base64url_decode($payload), true);
        if (!is_array($data) || !isset($data['sub'])) {
            return false;
        }

        // 检查过期
        if (isset($data['exp']) && $data['exp'] < time()) {
            return false;
        }

        return $data;
    }

    // ─────────────────────────── 工具 ───────────────────────────

    /**
     * 从 HTTP Header 中提取 Bearer token
     */
    private static function extractToken(): string
    {
        $header = '';
        // Apache / Nginx + PHP-FPM
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            // Apache mod_rewrite 可能吞掉 Authorization header，试试重定向后的
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
