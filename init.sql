-- =====================================================
-- 微信转账模拟系统 - MySQL 数据库初始化脚本
-- =====================================================
-- 使用方式：
-- 1. 登录 MySQL: mysql -u root -p
-- 2. 创建数据库: CREATE DATABASE wxtransfer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- 3. 选择数据库: USE wxtransfer;
-- 4. 执行本脚本: SOURCE init.sql;
-- =====================================================

-- -----------------------------------------------------
-- 1. 用户表（管理员和会员）
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '用户ID',
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '用户名',
    `password` VARCHAR(255) NOT NULL COMMENT '密码（加密存储）',
    `role` ENUM('admin', 'member') NOT NULL DEFAULT 'member' COMMENT '角色：admin=超级管理员，member=会员',
    `nickname` VARCHAR(100) DEFAULT '' COMMENT '昵称',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=正常，0=禁用',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- -----------------------------------------------------
-- 2. 转账记录表
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `transfers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '转账ID',
    `user_id` INT UNSIGNED NOT NULL COMMENT '所属用户ID',
    `title` VARCHAR(255) NOT NULL COMMENT '标题/金额',
    `description` VARCHAR(500) DEFAULT '' COMMENT '描述',
    `time` DATETIME NOT NULL COMMENT '转账时间',
    `transfer_type` VARCHAR(100) DEFAULT '微信商家转账' COMMENT '转账类型',
    `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
    `pay_method` VARCHAR(50) DEFAULT '零钱' COMMENT '收款方式',
    `received` TINYINT NOT NULL DEFAULT 0 COMMENT '是否已收款：0=未收，1=已收',
    `received_time` DATETIME DEFAULT NULL COMMENT '收款时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_received` (`received`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='转账记录表';

-- -----------------------------------------------------
-- 3. 插入默认管理员账号
-- -----------------------------------------------------
-- 密码: admin123 (加密后)
INSERT INTO `users` (`username`, `password`, `role`, `nickname`) VALUES
('admin', '$2y$10$8K1p/a0dL1LXMIgoEDFrwOfMQbLgT4rM4C8Xq7VXqXPxNQgMPxm4y', 'admin', '系统管理员'),
('member1', '$2y$10$8K1p/a0dL1LXMIgoEDFrwOfMQbLgT4rM4C8Xq7VXqXPxNQgMPxm4y', 'member', '测试会员1'),
('member2', '$2y$10$8K1p/a0dL1LXMIgoEDFrwOfMQbLgT4rM4C8Xq7VXqXPxNQgMPxm4y', 'member', '测试会员2');

-- -----------------------------------------------------
-- 4. 插入测试转账数据
-- -----------------------------------------------------
INSERT INTO `transfers` (`user_id`, `title`, `description`, `time`, `transfer_type`, `remark`, `pay_method`, `received`) VALUES
(2, '¥99.99', '微信商家转账', NOW(), '微信商家转账', '测试记录1', '零钱', 0),
(2, '¥188.88', '微信商家转账', DATE_SUB(NOW(), INTERVAL 1 DAY), '微信商家转账', '测试记录2', '零钱', 1),
(3, '¥66.66', '微信商家转账', NOW(), '微信商家转账', '会员2的记录', '零钱', 0);

-- =====================================================
-- 完成！
-- =====================================================
