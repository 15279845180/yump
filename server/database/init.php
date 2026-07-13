<?php
/**
 * VOS3000 多节点管理平台 — 一键数据库初始化
 * 运行方式: php init.php
 * 确保先创建数据库: CREATE DATABASE yump_vos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *
 * 使用 CREATE TABLE IF NOT EXISTS，不会删除已有数据
 * 如需重建，请先手动 DROP 对应表再运行
 */

// 检查 .env 文件（CLI 模式下 getenv() 可能不可靠）
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    echo "========================================\n";
    echo "  ⚠️  未找到 server/.env 配置文件！\n";
    echo "========================================\n\n";
    echo "请先创建 .env 文件：\n";
    echo "  cp server/.env.example server/.env\n\n";
    echo "然后编辑 server/.env 填入实际的数据库密码：\n";
    echo "  YUMP_DB_PASSWORD=你的MySQL密码\n\n";
    echo "如果已有 .env 但仍报错，请检查：\n";
    echo "  1. server/.env 文件是否存在\n";
    echo "  2. YUMP_DB_PASSWORD 是否填写正确\n\n";
    exit(1);
}

$dbConfig = require __DIR__ . '/../config/db.php';

echo "配置信息:\n";
echo "  主机: {$dbConfig['host']}:{$dbConfig['port']}\n";
echo "  数据库: {$dbConfig['dbname']}\n";
echo "  用户: {$dbConfig['username']}\n";
echo "  密码来源: server/.env\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}",
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("SET NAMES utf8mb4");

    echo "数据库连接成功: {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['dbname']}\n";
    echo "开始创建表...\n\n";

    // ===== 1. 节点表（核心基础表） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_nodes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '节点编号',
        `name` VARCHAR(100) NOT NULL COMMENT '节点名称',
        `ip_address` VARCHAR(50) NOT NULL COMMENT '公网IP',
        `version` VARCHAR(20) DEFAULT '' COMMENT '版本号',
        `api_base_url` VARCHAR(255) NOT NULL COMMENT 'API地址',
        `data_mode` TINYINT DEFAULT 0 COMMENT '数据方式: 0=拉取, 1=推送',
        `status` TINYINT DEFAULT 1 COMMENT '启用状态: 0=停用, 1=启用',
        `online_status` TINYINT DEFAULT 0 COMMENT '在线状态: 0=未知, 1=在线, 2=离线',
        `last_check_time` DATETIME DEFAULT NULL COMMENT '最后检测时间',
        `db_host` VARCHAR(50) DEFAULT '' COMMENT '数据库地址',
        `db_port` INT DEFAULT 3306 COMMENT '数据库端口',
        `db_name` VARCHAR(100) DEFAULT '' COMMENT '数据库名称',
        `db_username` VARCHAR(50) DEFAULT '' COMMENT '数据库用户名',
        `db_password` VARCHAR(255) DEFAULT '' COMMENT '数据库密码(AES加密base64)',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_status` (`status`),
        INDEX `idx_online` (`online_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VOS3000 节点配置'");
    echo "[OK] y_nodes — 节点配置表\n";

    // 迁移: 添加 fail_count 列（心跳脚本用，连续失败计数）
    try { $pdo->exec("ALTER TABLE `y_nodes` ADD COLUMN `fail_count` INT DEFAULT 0 COMMENT '连续失败次数(心跳)' AFTER `online_status`"); } catch (\Exception $e) {}
    // 迁移: 添加 sip_port 列（软电话 WebRTC 网关注册用，默认5060）
    try { $pdo->exec("ALTER TABLE `y_nodes` ADD COLUMN `sip_port` INT DEFAULT 5060 COMMENT 'SIP端口号(WebRTC)' AFTER `api_base_url`"); } catch (\Exception $e) {}

    // ===== 2. 对接网关表（双写：VOS3000 + 本地缓存） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_gateway_mappings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `node_id` INT NOT NULL COMMENT '所属节点ID',
        `name` VARCHAR(100) NOT NULL COMMENT '网关名称（VOS3000唯一标识）',
        `lock_type` TINYINT NOT NULL DEFAULT 0 COMMENT '锁定类型 0无锁定 3全部锁定',
        `call_level` TINYINT DEFAULT 4 COMMENT '权限 1网内 2市话 4国内 5国际',
        `capacity` INT DEFAULT 30 COMMENT '线路上限',
        `priority` INT DEFAULT 1 COMMENT '优先级',
        `register_type` TINYINT DEFAULT 0 COMMENT '注册类型 0静态 1动态',
        `remote_ips` VARCHAR(500) DEFAULT '' COMMENT '对接IP(逗号分隔)',
        `rtp_forward_type` TINYINT DEFAULT 1 COMMENT '媒体转发 0不转发 1转发 2转发+保持',
        `account` VARCHAR(100) DEFAULT '' COMMENT '计费账户号码',
        `callout_callee_prefixes_allow` TINYINT NOT NULL DEFAULT 1 COMMENT '被叫前缀: 1允许 0禁止',
        `callout_callee_prefixes` VARCHAR(500) DEFAULT '' COMMENT '被叫前缀(逗号分隔)',
        `callout_caller_prefixes_allow` TINYINT NOT NULL DEFAULT 1 COMMENT '主叫前缀: 1允许 0禁止',
        `callout_caller_prefixes` VARCHAR(500) DEFAULT '' COMMENT '主叫前缀(逗号分隔)',
        `rewrite_rules_out_callee` VARCHAR(1000) DEFAULT '' COMMENT '被叫改写规则',
        `rewrite_rules_out_caller` VARCHAR(1000) DEFAULT '' COMMENT '主叫改写规则',
        `routing_gateway_groups_allow` TINYINT NOT NULL DEFAULT 1 COMMENT '落地群组: 1允许 0禁止',
        `routing_gateway_groups` VARCHAR(500) DEFAULT '' COMMENT '落地群组(逗号分隔)',
        `caller_limit_e164_groups_allow` TINYINT NOT NULL DEFAULT 1,
        `caller_limit_e164_groups` VARCHAR(500) DEFAULT '',
        `callee_limit_e164_groups_allow` TINYINT NOT NULL DEFAULT 1,
        `callee_limit_e164_groups` VARCHAR(500) DEFAULT '',
        `deny_same_city_codes_allow` TINYINT NOT NULL DEFAULT 1,
        `deny_same_city_codes` VARCHAR(500) DEFAULT '',
        `max_call_duration_upper` INT DEFAULT -1 COMMENT '最长通话上限(秒)',
        `memo` TEXT COMMENT '备注',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_node_name` (`node_id`, `name`),
        KEY `idx_node` (`node_id`),
        FOREIGN KEY (`node_id`) REFERENCES `y_nodes`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对接网关配置缓存表'");
    echo "[OK] y_gateway_mappings — 对接网关表\n";

    // ===== 3. 落地网关表（双写：VOS3000 + 本地缓存） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_gateway_routings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `node_id` INT NOT NULL COMMENT '所属节点ID',
        `name` VARCHAR(100) NOT NULL COMMENT '网关名称（VOS3000唯一标识）',
        `lock_type` TINYINT NOT NULL DEFAULT 0 COMMENT '锁定类型 0无锁定 3全部锁定',
        `prefix` VARCHAR(500) DEFAULT '' COMMENT '落地前缀(逗号分隔)',
        `prefix_style` TINYINT DEFAULT 1 COMMENT '前缀匹配 0终结 1延续',
        `capacity` INT DEFAULT 30 COMMENT '线路上限',
        `priority` INT DEFAULT 1 COMMENT '优先级',
        `register_type` TINYINT DEFAULT 0 COMMENT '注册类型 0静态 1动态 2注册',
        `protocol` TINYINT DEFAULT 1 COMMENT '信令协议 0H323 1SIP',
        `remote_ip` VARCHAR(100) DEFAULT '' COMMENT '远端地址',
        `signal_port` INT DEFAULT 5060 COMMENT '信令端口',
        `rtp_forward_type` TINYINT DEFAULT 1 COMMENT '媒体转发 0不转发 1转发 2转发+保持',
        `clearing_account` VARCHAR(100) DEFAULT '' COMMENT '结算账户',
        `callin_caller_prefixes_allow` TINYINT NOT NULL DEFAULT 1 COMMENT '呼入主叫前缀: 1允许 0禁止',
        `callin_caller_prefixes` VARCHAR(500) DEFAULT '' COMMENT '呼入主叫前缀(逗号分隔)',
        `callin_callee_prefixes_allow` TINYINT NOT NULL DEFAULT 1 COMMENT '呼入被叫前缀: 1允许 0禁止',
        `callin_callee_prefixes` VARCHAR(500) DEFAULT '' COMMENT '呼入被叫前缀(逗号分隔)',
        `callin_forward_prefixes_allow` TINYINT NOT NULL DEFAULT 1 COMMENT '呼入前转前缀: 1允许 0禁止',
        `callin_forward_prefixes` VARCHAR(500) DEFAULT '' COMMENT '呼入前转前缀(逗号分隔)',
        `rewrite_rules_in_caller` VARCHAR(1000) DEFAULT '' COMMENT '主叫改写规则',
        `rewrite_rules_in_callee` VARCHAR(1000) DEFAULT '' COMMENT '被叫改写规则',
        `caller_limit_e164_groups_allow` TINYINT NOT NULL DEFAULT 1,
        `caller_limit_e164_groups` VARCHAR(500) DEFAULT '',
        `callee_limit_e164_groups_allow` TINYINT NOT NULL DEFAULT 1,
        `callee_limit_e164_groups` VARCHAR(500) DEFAULT '',
        `deny_same_city_codes_allow` TINYINT NOT NULL DEFAULT 1,
        `deny_same_city_codes` VARCHAR(500) DEFAULT '',
        `deny_caller_callee_allow` TINYINT NOT NULL DEFAULT 1,
        `deny_caller_callee` VARCHAR(500) DEFAULT '',
        `least_cost_routing` TINYINT NOT NULL DEFAULT 0 COMMENT '最低秒费率排序',
        `feerate_restrict` TINYINT NOT NULL DEFAULT 0 COMMENT '校验被叫费率',
        `memo` TEXT COMMENT '备注',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_node_name` (`node_id`, `name`),
        KEY `idx_node` (`node_id`),
        FOREIGN KEY (`node_id`) REFERENCES `y_nodes`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='落地网关配置缓存表'");
    echo "[OK] y_gateway_routings — 落地网关表\n";

    // 迁移：旧版本把 VOS "无" 误存为 -1，改回 INT_MAX(2147483647)
    $pdo->exec("UPDATE y_gateway_mappings SET capacity = 2147483647 WHERE capacity = -1");
    $pdo->exec("UPDATE y_gateway_routings SET capacity = 2147483647 WHERE capacity = -1");
    echo "[MIGRATE] gateway capacity -1 → 2147483647\n";

    // ===== 4. 账户缓存表（本地优先：sync→本地 / list→本地 / CUD→VOS→本地） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_customers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `node_id` INT NOT NULL COMMENT '所属节点ID',
        `account` VARCHAR(100) NOT NULL COMMENT '账户号码（VOS3000唯一标识）',
        `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '账户名称',
        `type` TINYINT NOT NULL DEFAULT 0 COMMENT '0普通账户 1电话卡 2结算账户',
        `lock_type` TINYINT NOT NULL DEFAULT 0 COMMENT '0正常 1锁定',
        `money` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '余额',
        `limit_money` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '透支限额',
        `today_consumption` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '今日消费',
        `fee_rate_group` VARCHAR(100) DEFAULT '' COMMENT '计费费率组',
        `agent_account` VARCHAR(100) DEFAULT '' COMMENT '代理商账户',
        `valid_time` BIGINT DEFAULT NULL COMMENT '有效期截止(时间戳ms)',
        `start_time` BIGINT DEFAULT NULL COMMENT '开户时间(时间戳ms)',
        `canceled` TINYINT NOT NULL DEFAULT 0 COMMENT '0正常 1已注销',
        `memo` TEXT COMMENT '备注',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_node_account` (`node_id`, `account`),
        KEY `idx_node_type` (`node_id`, `type`),
        KEY `idx_account` (`account`),
        FOREIGN KEY (`node_id`) REFERENCES `y_nodes`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账户信息本地缓存表'");
    echo "[OK] y_customers — 账户缓存表\n";
    // 兼容旧表（无 money/limit_money/today_consumption 列）
    try { $pdo->exec("ALTER TABLE y_customers ADD COLUMN `money` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '余额' AFTER `lock_type`"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE y_customers ADD COLUMN `limit_money` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '透支限额' AFTER `money`"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE y_customers ADD COLUMN `today_consumption` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '今日消费' AFTER `limit_money`"); } catch (\Exception $e) {}

    // ===== 5. 费率组缓存表 =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_fee_rates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `node_id` INT NOT NULL COMMENT '所属节点ID',
        `group_name` VARCHAR(100) NOT NULL COMMENT '费率组名称',
        `remark` VARCHAR(255) DEFAULT '' COMMENT '备注（本地维护，VOS同步不会覆盖）',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_node_group` (`node_id`, `group_name`),
        KEY `idx_node` (`node_id`),
        FOREIGN KEY (`node_id`) REFERENCES `y_nodes`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='费率组缓存表'");
    // 兼容旧表：增量添加 remark / updated_at
    try { $pdo->exec("ALTER TABLE y_fee_rates ADD COLUMN `remark` VARCHAR(255) DEFAULT '' COMMENT '备注' AFTER `group_name`"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE y_fee_rates ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (\Exception $e) {}
    echo "[OK] y_fee_rates — 费率组缓存表\n";

    // ===== 5.1 缴费记录表（充值/扣费/归零/透支修改） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_payment_records` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `node_id` INT NOT NULL COMMENT '所属节点ID',
        `account` VARCHAR(100) NOT NULL COMMENT '账户号码',
        `account_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '账户名称',
        `type` VARCHAR(20) NOT NULL COMMENT '类型: recharge充值/deduct扣费/reset归零/overdraft透支修改',
        `amount` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '变动金额',
        `old_value` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '变动前值',
        `new_value` DECIMAL(12,4) DEFAULT 0.0000 COMMENT '变动后值',
        `memo` TEXT COMMENT '备注',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_node_account` (`node_id`, `account`),
        KEY `idx_type` (`type`),
        KEY `idx_created` (`created_at`),
        FOREIGN KEY (`node_id`) REFERENCES `y_nodes`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='缴费/透支记录表'");
    echo "[OK] y_payment_records — 缴费记录表\n";

    // ===== 6. 话机主表（本地优先：sync→本地 / list→本地 / online→本地+VOS / CUD→VOS→本地） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_phones` (
        `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增ID',
        `node_id` INT NOT NULL COMMENT '所属节点ID',
        `e164` VARCHAR(100) NOT NULL COMMENT '话机号码',
        `password` VARCHAR(100) DEFAULT '' COMMENT '配置密码',
        `caller_id` VARCHAR(100) DEFAULT '' COMMENT '去电显示',
        `account` VARCHAR(100) DEFAULT '' COMMENT '关联普通账户号码',
        `lock_type` TINYINT NOT NULL DEFAULT 0 COMMENT '0正常 1锁定',
        `call_level` TINYINT DEFAULT 4 COMMENT '权限 1网内 2市话 4国内 5国际',
        `concurrent_limit` INT DEFAULT 1 COMMENT '并发上限',
        `memo` VARCHAR(500) DEFAULT '' COMMENT '备注',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_node_e164` (`node_id`, `e164`),
        KEY `idx_node` (`node_id`),
        KEY `idx_e164` (`e164`),
        FOREIGN KEY (`node_id`) REFERENCES `y_nodes`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='话机信息本地缓存表'");

    // 迁移: 旧列名 → 新列名（如果表已存在且包含旧列）
    $cols = $pdo->query("SHOW COLUMNS FROM `y_phones`")->fetchAll(\PDO::FETCH_COLUMN);
    if (in_array('display_number', $cols) && !in_array('caller_id', $cols)) {
        $pdo->exec("ALTER TABLE `y_phones` CHANGE `display_number` `caller_id` VARCHAR(100) DEFAULT '' COMMENT '去电显示'");
        echo "[MIGRATE] y_phones.display_number → caller_id\n";
    }
    if (in_array('line_capacity', $cols) && !in_array('concurrent_limit', $cols)) {
        $pdo->exec("ALTER TABLE `y_phones` CHANGE `line_capacity` `concurrent_limit` INT DEFAULT 1 COMMENT '并发上限'");
        echo "[MIGRATE] y_phones.line_capacity → concurrent_limit\n";
    }
    echo "[OK] y_phones — 话机主表\n";

    // ===== 7. 用户表（认证系统） =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '用户ID',
        `username` VARCHAR(50) NOT NULL COMMENT '登录用户名',
        `password_hash` VARCHAR(255) NOT NULL COMMENT '密码哈希(bcrypt)',
        `nickname` VARCHAR(50) DEFAULT '' COMMENT '显示昵称',
        `role` VARCHAR(50) NOT NULL DEFAULT 'admin' COMMENT '角色标识(预留扩展)',
        `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 0=禁用, 1=启用',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台用户表'");
    echo "[OK] y_users — 用户表\n";

    // 插入默认管理员账号（admin / yump）
    $adminHash = password_hash('yump', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT IGNORE INTO y_users (username, password_hash, nickname, role) VALUES (?, ?, ?, ?)');
    $stmt->execute(['admin', $adminHash, '系统管理员', 'admin']);
    if ($stmt->rowCount() > 0) {
        echo "[OK] 默认管理员账号已创建: admin / yump\n";
    }

    // ===== 8. 操作日志表 =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_operation_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT DEFAULT NULL COMMENT '操作用户ID',
        `username` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '操作用户名',
        `operation_type` VARCHAR(50) NOT NULL COMMENT '操作类型: create/modify/delete/sync/login/pay/overdraft',
        `module` VARCHAR(50) NOT NULL COMMENT '模块: customer/phone/gateway_mapping/gateway_routing/node/auth',
        `target` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '操作目标',
        `ip_address` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '操作IP',
        `detail` TEXT COMMENT '详细信息',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user` (`user_id`),
        KEY `idx_type` (`operation_type`),
        KEY `idx_module` (`module`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志表'");
    echo "[OK] y_operation_logs — 操作日志表\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_cdr_counter` (
        `date` DATE NOT NULL COMMENT '日期(YYYY-MM-DD)',
        `node_id` INT NOT NULL DEFAULT 0 COMMENT '节点ID',
        `total` BIGINT NOT NULL DEFAULT 0 COMMENT '当日该节点话单总数',
        PRIMARY KEY (`date`, `node_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CDR按天按节点预聚合计数器'");
    echo "[OK] y_cdr_counter — CDR计数器表\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_cdr_hourly` (
        `date` DATE NOT NULL COMMENT '日期(YYYY-MM-DD)',
        `hour` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '小时(0-23)',
        `node_id` INT NOT NULL DEFAULT 0 COMMENT '节点ID',
        `direction` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '呼叫方向(inbound/outbound)',
        `calls` INT UNSIGNED NOT NULL DEFAULT 0,
        `answered` INT UNSIGNED NOT NULL DEFAULT 0,
        `total_duration` BIGINT NOT NULL DEFAULT 0,
        `bill_duration` BIGINT NOT NULL DEFAULT 0,
        `fee` DECIMAL(14,4) NOT NULL DEFAULT 0,
        `cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
        `b1` INT UNSIGNED NOT NULL DEFAULT 0,
        `b2` INT UNSIGNED NOT NULL DEFAULT 0,
        `b3` INT UNSIGNED NOT NULL DEFAULT 0,
        `b4` INT UNSIGNED NOT NULL DEFAULT 0,
        `b5` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`date`, `hour`, `node_id`, `direction`),
        KEY `idx_date_node` (`date`, `node_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CDR按小时预聚合(趋势/KPI)'");
    echo "[OK] y_cdr_hourly — CDR按小时汇总表\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_cdr_daily_dim` (
        `date` DATE NOT NULL COMMENT '日期(YYYY-MM-DD)',
        `node_id` INT NOT NULL DEFAULT 0 COMMENT '节点ID',
        `dim` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '维度: gateway_in/gateway_out/account/caller_prefix/disconnect_cause',
        `dim_value` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '维度值',
        `calls` INT UNSIGNED NOT NULL DEFAULT 0,
        `answered` INT UNSIGNED NOT NULL DEFAULT 0,
        `total_duration` BIGINT NOT NULL DEFAULT 0,
        `fee` DECIMAL(14,4) NOT NULL DEFAULT 0,
        `cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (`date`, `node_id`, `dim`, `dim_value`),
        KEY `idx_date_dim` (`date`, `dim`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CDR按维度预聚合(TOP-N)'");
    echo "[OK] y_cdr_daily_dim — CDR按维度汇总表\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `y_cdr_summary` (
        `date` DATE NOT NULL COMMENT '日期(YYYY-MM-DD)',
        total_count BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日话单总条数',
        PRIMARY KEY (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDR按天总数汇总(无条件查询毫秒级取总数)'");
    echo "[OK] y_cdr_summary — CDR总数汇总表\n";

    echo "\n所有表初始化完成！\n";
    echo "使用的表: y_nodes, y_gateway_mappings, y_gateway_routings, y_customers, y_phones, y_payment_records\n";
    echo "预留表(未来可能使用): y_fee_rates\n";

} catch (PDOException $e) {
    echo "[ERROR] 数据库错误: " . $e->getMessage() . "\n";
    exit(1);
}
