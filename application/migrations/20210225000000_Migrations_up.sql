CREATE TABLE IF NOT EXISTS `migrations` (
  `id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `modified_at` DATETIME NULL,
  `migrated_at` DATETIME NULL,
  `rolled_back_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `name` (`name` ASC),
  INDEX `created_at` (`created_at` ASC),
  INDEX `modified_at` (`modified_at` ASC),
  INDEX `migrated_at` (`migrated_at` ASC),
  INDEX `rolled_back_at` (`rolled_back_at` ASC)
) ENGINE = InnoDB;
