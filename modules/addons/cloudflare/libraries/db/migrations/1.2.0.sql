# migrations for version 1.2.0

ALTER IGNORE TABLE cloudflare_zone DROP PRIMARY KEY, ADD PRIMARY KEY (whmcs_id, active_zone);
ALTER TABLE cloudflare_plans ADD COLUMN plan_tag VARCHAR(255);
ALTER TABLE cloudflare_zone ADD COLUMN plan_tag VARCHAR(255);
ALTER TABLE cloudflare_zone ADD COLUMN sub_id int(10);
