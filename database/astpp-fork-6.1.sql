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
