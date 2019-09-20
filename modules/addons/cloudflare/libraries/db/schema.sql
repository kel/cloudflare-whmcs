# Tables necessary for the integration

CREATE TABLE IF NOT EXISTS cloudflare_plans (
  addonid int(10),
  pricingid int(10),
  cf_auth_dns bool,
  plan_tag VARCHAR(255),
  defaulton tinyint(4) DEFAULT '0'
);

CREATE TABLE IF NOT EXISTS cloudflare_zone(
  whmcs_id int(10),
  user_key VARCHAR(255),
  active_zone VARCHAR(255),
  nameservers VARCHAR(255),
  plan_tag VARCHAR(255),
  sub_id int(10),
  PRIMARY KEY (whmcs_id, active_zone)
);

CREATE TABLE IF NOT EXISTS cloudflare_log (
  log_id int(10) NOT NULL AUTO_INCREMENT,
  date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  message VARCHAR(1000),
  PRIMARY KEY (`log_id`)
);

CREATE TABLE IF NOT EXISTS cloudflare_ulog (
  log_id int(10) NOT NULL AUTO_INCREMENT,
  date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  whmcs_id int(10),
  message VARCHAR(1000),
  PRIMARY KEY (`log_id`)
);

