-- ========================================
-- Feature Settings Table (Single Tenant)
-- ========================================
CREATE TABLE IF NOT EXISTS `feature_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `enable_commission` TINYINT(1) DEFAULT 1 COMMENT 'Enable Watak/Commission features (1=Yes, 0=No)',
  `enable_purchase` TINYINT(1) DEFAULT 1 COMMENT 'Enable Vendor Purchase/Invoice features (1=Yes, 0=No)',
  `enable_ai` TINYINT(1) DEFAULT 1 COMMENT 'Enable AI features (1=Yes, 0=No)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Global feature flags for single tenant system';

-- ========================================
-- Insert default settings (all features enabled)
-- ========================================
INSERT INTO `feature_settings` (`id`, `enable_commission`, `enable_purchase`, `enable_ai`)
VALUES (1, 1, 1, 1)
ON DUPLICATE KEY UPDATE 
  `enable_commission` = VALUES(`enable_commission`),
  `enable_purchase` = VALUES(`enable_purchase`),
  `enable_ai` = VALUES(`enable_ai`);
