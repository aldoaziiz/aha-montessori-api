CREATE TABLE IF NOT EXISTS `app_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_group` VARCHAR(100) NOT NULL DEFAULT 'general',
    `value_type` VARCHAR(30) NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `app_settings_setting_key_unique` (`setting_key`)
);

INSERT INTO `app_settings` (
    `setting_key`,
    `setting_value`,
    `setting_group`,
    `value_type`,
    `description`,
    `created_at`,
    `updated_at`
) VALUES (
    'public_registration_enabled',
    '0',
    'registration',
    'boolean',
    'Controls whether the public registration form accepts submissions.',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
