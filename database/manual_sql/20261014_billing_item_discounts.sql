ALTER TABLE `billing_items`
    ADD COLUMN `discount_percentage` DECIMAL(5, 2) NOT NULL DEFAULT 0.00 AFTER `subtotal`,
    ADD COLUMN `discount_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 AFTER `discount_percentage`;
