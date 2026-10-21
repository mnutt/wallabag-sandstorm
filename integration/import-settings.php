<?php
// Apply the fixed Sandstorm import configuration to new and existing grains.
// Only write on change; do not boot the full application on every launch.
$db = new PDO('sqlite:' . getenv('WALLABAG_DATABASE'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("UPDATE wallabag_internal_setting SET value = '1' WHERE name = 'import_with_redis' AND value != '1'");
$db->exec("UPDATE wallabag_internal_setting SET value = '0' WHERE name = 'import_with_rabbitmq' AND value != '0'");
// No foreign key: retain the checkpoint if a user deletes an unfinished entry.
$db->exec('CREATE TABLE IF NOT EXISTS sandstorm_import_pending (job_key TEXT PRIMARY KEY, user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL)');
