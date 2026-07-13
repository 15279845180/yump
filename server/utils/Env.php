<?php

/**
 * 环境变量统一加载器
 *
 * 解决 PHP CLI 模式下 getenv() 依赖 php.ini variables_order 的问题。
 * 优先从 server/.env 文件读取，兼容 getenv() 回退（Web 模式通过 Nginx fastcgi_param）。
 *
 * 用法：
 *   require_once __DIR__ . '/Env.php';
 *   $dbPassword = Env::get('YUMP_DB_PASSWORD', 'fallback_default');
 *
 * .env 文件格式（server/.env）：
 *   # 注释
 *   KEY=value
 *   KEY2="带空格的 value"
 */
class Env
{
    /** @var array<string, string>|null 缓存已解析的 .env */
    private static $cache = null;

    /** @var string .env 文件路径 */
    private static $filePath = null;

    /**
     * 获取环境变量值
     *
     * @param string $key     变量名
     * @param string $default 默认值（当 .env 和 getenv() 都没有时）
     * @return string
     */
    public static function get(string $key, string $default = ''): string
    {
        self::load();

        // 优先：.env 文件
        if (isset(self::$cache[$key]) && self::$cache[$key] !== '') {
            return self::$cache[$key];
        }

        // 其次：getenv()（兼容 Nginx fastcgi_param 方式）
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        // 最后：默认值
        return $default;
    }

    /**
     * 检查环境变量是否存在且非空
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    /**
     * 获取所有已加载的环境变量（调试用）
     * @return array<string, string>
     */
    public static function all(): array
    {
        self::load();
        return self::$cache ?? [];
    }

    // ─────────────────── 内部实现 ───────────────────

    /**
     * 懒加载：解析一次 .env 并缓存
     */
    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        $path = self::resolvePath();
        self::$cache = [];

        if ($path === null || !file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // 跳过注释和空行
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // 解析 KEY=VALUE
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // 去除引号
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key !== '') {
                self::$cache[$key] = $value;
            }
        }
    }

    /**
     * 定位 .env 文件：server/.env
     * 基于当前文件位置（server/utils/Env.php）向上找
     */
    private static function resolvePath(): ?string
    {
        if (self::$filePath !== null) {
            return self::$filePath;
        }

        // server/utils/ → server/
        $candidates = [
            __DIR__ . '/../.env',                 // server/.env（标准位置）
            __DIR__ . '/../../../.env',            // 项目根 .env（兼容旧习惯）
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                self::$filePath = $path;
                return $path;
            }
        }

        // 都不存在，返回标准位置供调用方判断
        self::$filePath = $candidates[0];
        return null;
    }

    /**
     * 获取 .env 文件路径（供调用方显示错误信息）
     */
    public static function filePath(): string
    {
        self::load();
        return self::$filePath ?? (__DIR__ . '/../.env');
    }
}
