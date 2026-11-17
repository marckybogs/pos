-- ============================================================
-- POS SYSTEM DATABASE SCHEMA
-- Compatible with phpMyAdmin / XAMPP MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS `pos_system`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `pos_system`;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(50) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `full_name`  VARCHAR(100) NOT NULL,
  `role`       ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: categories
-- ============================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL UNIQUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: products
-- ============================================================
CREATE TABLE IF NOT EXISTS `products` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `product_name`   VARCHAR(150) NOT NULL,
  `category`       VARCHAR(100) NOT NULL DEFAULT 'Uncategorized',
  `barcode`        VARCHAR(100) DEFAULT NULL,
  `cost_price`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` INT(11) NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_name` (`product_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: sales
-- ============================================================
CREATE TABLE IF NOT EXISTS `sales` (
  `id`               INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_number`   VARCHAR(50) NOT NULL UNIQUE,
  `user_id`          INT(11) NOT NULL,
  `subtotal`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `vat_percent`      DECIMAL(5,2) NOT NULL DEFAULT 12.00,
  `vat_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `change_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method`   ENUM('cash','card','gcash','other') NOT NULL DEFAULT 'cash',
  `status`           ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sales_user` (`user_id`),
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: sale_items
-- ============================================================
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `sale_id`        INT(11) NOT NULL,
  `product_id`     INT(11) NOT NULL,
  `product_name`   VARCHAR(150) NOT NULL,
  `quantity`       INT(11) NOT NULL DEFAULT 1,
  `unit_price`     DECIMAL(10,2) NOT NULL,
  `cost_price`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total`     DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_items_sale` (`sale_id`),
  KEY `fk_items_product` (`product_id`),
  CONSTRAINT `fk_items_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` TEXT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Default admin: admin / admin123
-- Default cashier: cashier / cashier123
INSERT INTO `users` (`username`,`password`,`full_name`,`role`) VALUES
('admin',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin'),
('cashier', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Cashier',  'cashier');

-- NOTE: Both passwords above are hashed "password" — change on first login!
-- To generate correct hashes use: password_hash('yourpassword', PASSWORD_DEFAULT)

INSERT INTO `categories` (`name`) VALUES
('Beverages'),('Snacks'),('Dairy'),('Bakery'),('Personal Care'),('Cleaning'),('Frozen'),('Canned Goods');

INSERT INTO `products` (`product_name`,`category`,`barcode`,`cost_price`,`selling_price`,`stock_quantity`) VALUES
('Coca-Cola 1.5L',       'Beverages',    '4902102113946', 45.00,  65.00,  120),
('Pepsi 1.5L',           'Beverages',    '4902102113947', 43.00,  62.00,  95),
('Mineral Water 500ml',  'Beverages',    '6901028117166', 12.00,  18.00,  200),
('Lays Classic 95g',     'Snacks',       '5901234123457', 38.00,  55.00,  80),
('Pringles Original',    'Snacks',       '5901234123458', 55.00,  79.00,  60),
('Cheese Burger',        'Snacks',       '5901234123459', 30.00,  45.00,  40),
('Fresh Milk 1L',        'Dairy',        '5901234123460', 58.00,  78.00,  50),
('Yogurt Strawberry',    'Dairy',        '5901234123461', 25.00,  38.00,  45),
('Tasty Bread',          'Bakery',       '5901234123462', 35.00,  52.00,  30),
('Pandesal Pack 10pcs',  'Bakery',       '5901234123463', 28.00,  40.00,  25),
('Shampoo 200ml',        'Personal Care','5901234123464', 85.00,  120.00, 35),
('Soap Bar 3-Pack',      'Personal Care','5901234123465', 40.00,  58.00,  70),
('Dishwashing Liquid',   'Cleaning',     '5901234123466', 30.00,  45.00,  55),
('Frozen Hotdog 250g',   'Frozen',       '5901234123467', 65.00,  95.00,  40),
('Sardines in Tomato',   'Canned Goods', '5901234123468', 22.00,  32.00,  5);

INSERT INTO `settings` (`setting_key`,`setting_val`) VALUES
('shop_name',     'QuickMart POS'),
('shop_address',  '123 Main Street, City'),
('shop_phone',    '+63 912 345 6789'),
('vat_percent',   '12'),
('currency',      '₱'),
('low_stock_threshold', '10'),
('receipt_footer','Thank you for shopping with us!');

-- ============================================================
-- TABLE: gcash_maya_sessions
-- One row per "Complete Transaction" click on gcash_maya.php
-- ============================================================
-- Allow product_id to be NULL so GCash/Maya service fee rows
-- don't require a matching products record.
-- Safe to run even if already applied.
ALTER TABLE `sale_items`
  MODIFY COLUMN `product_id` INT(11) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `gcash_maya_sessions` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `sale_id`       INT(11) DEFAULT NULL COMMENT 'linked sales row (NULL until completed)',
  `user_id`       INT(11) NOT NULL,
  `total_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_profit`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`        ENUM('pending','done','merged_pos') NOT NULL DEFAULT 'pending',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_gm_user` (`user_id`),
  CONSTRAINT `fk_gm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: gcash_maya_items
-- Individual lines within a gcash_maya session
-- ============================================================
CREATE TABLE IF NOT EXISTS `gcash_maya_items` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `session_id`     INT(11) NOT NULL,
  `service_type`   ENUM('Cash In/Out','E-Loading','Bills Payment') NOT NULL,
  `transaction_type` VARCHAR(50) NOT NULL COMMENT 'Cash In, Cash Out, E-Loading, Bills Payment',
  `amount`         DECIMAL(10,2) NOT NULL,
  `service_fee`    DECIMAL(10,2) NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_gmi_session` (`session_id`),
  CONSTRAINT `fk_gmi_session` FOREIGN KEY (`session_id`) REFERENCES `gcash_maya_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
