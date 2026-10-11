-- ASTPP 6.1 (infrahost fork) - applied by astpp-debian13/install.sh after astpp-6.0*.sql and by
-- tools/astpp-deploy.sh on every deploy. Every statement must be safe to run again.

-- Version shown in the footer
UPDATE `system` SET value = '6.1' WHERE name = 'version' AND value <> '6.1';

-- The seed data shipped the vendor's company details as company profile of the admin account
-- (company name, address in Ahmedabad, sales e-mail, phone, tax number, website title/footer).
-- They appeared in page titles, invoices and e-mails. Cleared only while they are unchanged;
-- the operator enters its own details under Configuration / Company Profile (or the installer
-- sets them from install.conf).
UPDATE invoice_conf
   SET company_name = '', address = '', city = '', province = '', zipcode = '', telephone = '',
       fax = '', emailaddress = '', website = '', invoice_taxes_number = '', domain = '',
       website_title = 'ASTPP', website_footer = ''
 WHERE accountid = 1 AND company_name = 'Inextrix Technologies Pvt. Ltd.';

-- Country list: the row for +372 (ISO EE, capital Tallinn) was labelled ERITREA, so Estonia
-- was missing from every country drop-down and Eritrea appeared twice.
UPDATE countrycode SET country = 'ESTONIA' WHERE id = 61 AND iso = 'EE' AND countrycode = 372 AND country = 'ERITREA';

-- Provisioning API (/api/v1, controllers/api.php): API keys, managed in the portal (API Access).
-- key_id is the public part of the key, secret_hash the SHA-256 of the secret part.
-- allowed_ips: comma separated IPv4/IPv6 addresses or networks; a key only works from these.
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `accountid` int(11) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `key_id` char(16) NOT NULL,
  `secret_hash` char(64) NOT NULL,
  `allowed_ips` text NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0-Active,1-Disabled',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_date` datetime NOT NULL,
  `last_used_date` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_id` (`key_id`),
  KEY `accountid` (`accountid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Menu "API Access": admin and reseller under Configuration, customers under My Account.
-- The menu is built at login; users see the new entry after their next login.
-- Fixed ids 9001/9002: the seed permission lists already reference ids up to 692 for menus of
-- add-ons that are not installed, so auto increment ids (563, 564 ...) would collide with them.
INSERT INTO menu_modules (id, menu_label, module_name, module_url, menu_title, menu_image, menu_subtitle, priority)
SELECT 9001, 'API Access', 'apikeys', 'apikeys/apikeys_list/', 'Configuration', 'Configurations.png', '0', 90.95 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM menu_modules WHERE id = 9001 OR (module_url = 'apikeys/apikeys_list/' AND menu_title = 'Configuration'));
INSERT INTO menu_modules (id, menu_label, module_name, module_url, menu_title, menu_image, menu_subtitle, priority)
SELECT 9002, 'API Access', 'apikeys', 'apikeys/apikeys_list/', 'My Account', 'PaymentReport.png', '0', 7.7 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM menu_modules WHERE id = 9002 OR (module_url = 'apikeys/apikeys_list/' AND menu_title = 'My Account'));
UPDATE userlevels U
  JOIN menu_modules M ON M.id = 9001
   SET U.module_permissions = CONCAT(U.module_permissions, ',', M.id)
 WHERE U.userlevelid IN (-1, 1) AND NOT FIND_IN_SET(M.id, U.module_permissions);
UPDATE userlevels U
  JOIN menu_modules M ON M.id = 9002
   SET U.module_permissions = CONCAT(U.module_permissions, ',', M.id)
 WHERE U.userlevelid = 0 AND NOT FIND_IN_SET(M.id, U.module_permissions);

-- PayPal payments (audit O10): every PayPal form is created on the server with the amount, the
-- currency and the receiver it expects; the IPN (pages/paypal_response) is verified with PayPal
-- (cmd=_notify-validate), compared with this row and booked once (txn_id unique, status 0 -> 1).
-- kind: order (ref_id = orders.id), topup, invoice (ref_id = invoices.id); credit = amount to book
-- in base currency.
CREATE TABLE IF NOT EXISTS `paypal_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` char(32) NOT NULL,
  `accountid` int(11) NOT NULL,
  `kind` varchar(10) NOT NULL,
  `ref_id` int(11) NOT NULL DEFAULT 0,
  `amount` decimal(20,5) NOT NULL,
  `currency` char(3) NOT NULL,
  `credit` decimal(20,5) NOT NULL,
  `receiver` varchar(127) NOT NULL,
  `receiver_reseller_id` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0-Pending,1-Booked',
  `txn_id` varchar(32) DEFAULT NULL,
  `created_date` datetime NOT NULL,
  `booked_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  UNIQUE KEY `txn_id` (`txn_id`),
  KEY `accountid` (`accountid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Password reset codes (audit M8, signup/confirmpassword and signup/reset_password): one row per
-- account. code_hash = HMAC-SHA256 of "accountid|code" with the ENCRYPTION_KEY ('' = used up),
-- attempts per code, issued = codes sent since window_start (limit per hour). Upstream kept the
-- code in account_unverified, together with a copy of the account password.
CREATE TABLE IF NOT EXISTS `password_resets` (
  `accountid` int(11) NOT NULL,
  `code_hash` char(64) NOT NULL DEFAULT '',
  `created_date` datetime NOT NULL,
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `issued` tinyint(4) NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`accountid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Portal passwords as password_hash() (audit M6, common::password_store()): an Argon2id hash has
-- about 100 characters (bcrypt 60), the columns held only the Blowfish value. Widened once (the
-- check keeps every later deploy from rebuilding the table). Old Blowfish values stay valid and
-- are replaced by a hash at the next successful login of the account.
SET @astpp_sql = IF((SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'password') < 255,
                    'ALTER TABLE accounts MODIFY `password` varchar(255) NOT NULL DEFAULT \'\'', 'DO 0');
PREPARE astpp_stmt FROM @astpp_sql;
EXECUTE astpp_stmt;
DEALLOCATE PREPARE astpp_stmt;
SET @astpp_sql = IF((SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_unverified' AND COLUMN_NAME = 'password') < 255,
                    'ALTER TABLE account_unverified MODIFY `password` varchar(255) NOT NULL', 'DO 0');
PREPARE astpp_stmt FROM @astpp_sql;
EXECUTE astpp_stmt;
DEALLOCATE PREPARE astpp_stmt;
-- The old "forgot password" flow copied the Blowfish password of the account into
-- account_unverified, signups stored their generated password there (both readable with the
-- PRIVATE_KEY). The fork reads neither: a signup account gets its password through the link in the
-- welcome mail. "!" matches no password (common::password_locked()).
UPDATE account_unverified SET password = '!' WHERE password <> '!' AND password NOT LIKE '$%';
