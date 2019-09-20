# migrations for version 1.3.0

ALTER TABLE `cloudflare_log` ADD `log_id` INT NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`log_id`);
ALTER TABLE `cloudflare_ulog` ADD `log_id` INT NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`log_id`);
ALTER TABLE `cloudflare_plans` ADD `defaulton` TINYINT NOT NULL DEFAULT '0' ;
