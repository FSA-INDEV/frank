-- ==============================================================================
--  🐘 FrankenPHP Development Server - Dummy Database Initialization
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `app_dev` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `app_dev`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `role` ENUM('admin', 'developer', 'manager', 'user') DEFAULT 'user',
    `status` ENUM('active', 'pending', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Products Table
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `sku` VARCHAR(50) NOT NULL UNIQUE,
    `price` DECIMAL(10, 2) NOT NULL,
    `stock` INT NOT NULL DEFAULT 0,
    `category` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Orders Table
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `status` ENUM('pending', 'paid', 'shipped', 'cancelled') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Messages / Real-Time Events Table
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `topic` VARCHAR(255) NOT NULL,
    `payload` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Sample Data
INSERT INTO `users` (`name`, `email`, `role`, `status`) VALUES
('Juan David Martínez', 'juan@example.com', 'admin', 'active'),
('Elena Rostova', 'elena@example.com', 'developer', 'active'),
('Marcus Vance', 'marcus@example.com', 'manager', 'active'),
('Sarah Connor', 'sarah@example.com', 'developer', 'active'),
('Alex Rivera', 'alex@example.com', 'user', 'pending')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `products` (`name`, `sku`, `price`, `stock`, `category`) VALUES
('FrankenPHP Pro Mug', 'MUG-FRANK-01', 19.99, 150, 'Merchandise'),
('Mechanical Keyboard RGB', 'KB-RGB-PRO', 129.50, 45, 'Hardware'),
('4K UltraWide Monitor 34"', 'MON-34-4K', 499.00, 20, 'Hardware'),
('PHP 8.5 Masterclass Course', 'CRS-PHP-85', 79.99, 999, 'Digital'),
('Mercure Realtime Handbook', 'BK-MERCURE-01', 29.99, 200, 'Books')
ON DUPLICATE KEY UPDATE `price`=VALUES(`price`);

INSERT INTO `orders` (`user_id`, `total_amount`, `status`) VALUES
(1, 149.49, 'paid'),
(2, 499.00, 'shipped'),
(3, 19.99, 'paid'),
(4, 79.99, 'pending')
ON DUPLICATE KEY UPDATE `total_amount`=VALUES(`total_amount`);
