-- uninstall.sql
-- Copyright (C) 2026 Stefano Natangelo
-- SPDX-License-Identifier: AGPL-3.0-only
--
-- Journal Quota: remove everything the plugin stored in the OJS database.
--
-- Run it ONLY after disabling and deleting the plugin (see "Uninstalling" in
-- README.md) and ONLY after backing up the database. It cannot be undone.
--
-- Tested on MariaDB with OJS 3.5.0-6. The statements are standard SQL; on
-- other engines review them before running. If your OJS tables use a prefix,
-- add it to every table name below.
--
--   MySQL/MariaDB:  mysql -u USER -p DATABASE < uninstall.sql
--   PostgreSQL:     psql -U USER -d DATABASE -f uninstall.sql

-- 1. The plugin's own tables (file ledger and upload reservations)
DROP TABLE IF EXISTS journal_quota_reservations;
DROP TABLE IF EXISTS journal_quota_files;

-- 2. Site and per-journal settings (quotas, thresholds, alert state)
DELETE FROM plugin_settings WHERE plugin_name = 'journalquotaplugin';

-- 3. "Disk Quota Alert" email: default text and any journal customisations
DELETE FROM email_templates_settings
 WHERE email_id IN (SELECT email_id FROM email_templates WHERE email_key = 'JOURNAL_QUOTA_NOTIFY');
DELETE FROM email_templates WHERE email_key = 'JOURNAL_QUOTA_NOTIFY';
DELETE FROM email_templates_default_data WHERE email_key = 'JOURNAL_QUOTA_NOTIFY';

-- 4. Task notifications left behind if the plugin was deleted without being
--    disabled first (type = NOTIFICATION_TYPE_PLUGIN_BASE + 0x51A0)
DELETE FROM notification_settings
 WHERE notification_id IN (SELECT notification_id FROM notifications WHERE type = 100684193);
DELETE FROM notifications WHERE type = 100684193;

-- 5. Installed-version record, so a later installation starts from scratch
DELETE FROM versions WHERE product_type = 'plugins.generic' AND product = 'journalQuota';
