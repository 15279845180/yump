<?php
/**
 * 字段加密/解密工具
 *
 * 使用 PHP openssl_encrypt / openssl_decrypt (AES-256-CBC)，密钥从环境变量读取
 * 环境变量：YUMP_FIELD_KEY（16/24/32 字节字符串）
 *
 * 如果环境变量未设置，使用 fallback（仅供开发，生产必须设置）
 */
class Crypto {
    // 加密用的固定 key fallback（开发用，生产应在环境变量里覆盖）
    private const FALLBACK_KEY = 'yump_vos_default_key_32_bytes_xx'; // 32 字节

    // 获取密钥（返回恰好 16/24/32 字节的字符串）
    public static function key(): string {
        require_once __DIR__ . '/Env.php';
        $envKey = Env::get('YUMP_FIELD_KEY');
        if ($envKey !== '') {
            // 用户自定义密钥：截断/补齐到 32 字节
            return substr(str_pad($envKey, 32, '0'), 0, 32);
        }
        return self::FALLBACK_KEY;
    }

    /**
     * 加密（返回 base64 字符串，方便存 VARBINARY）
     * 失败返回 null
     */
    public static function encrypt(?string $plain): ?string {
        if ($plain === null || $plain === '') {
            return null;
        }
        $key = self::key();
        $encrypted = openssl_encrypt(
            $plain,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            substr($key, 0, 16)  // IV 取 key 前 16 字节（必须固定才能解密）
        );
        return $encrypted === false ? null : base64_encode($encrypted);
    }

    /**
     * 解密（输入 base64 字符串）
     * 失败返回 null
     */
    public static function decrypt(?string $cipher): ?string {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        $raw = base64_decode($cipher, true);
        if ($raw === false) return null;

        $key = self::key();
        $decrypted = openssl_decrypt(
            $raw,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            substr($key, 0, 16)
        );
        return $decrypted === false ? null : $decrypted;
    }
}
