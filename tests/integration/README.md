# Disposable WordPress integration tests

These scripts write content, reset the link inventory and create a temporary SQL
trigger. Never run them on a working LocalWP site or a production database.
Use a separate WordPress directory and database with synthetic posts only.
Reusing the PHP and MySQL services of LocalWP is sufficient; do not copy its
`wp-config.php`, production credentials, uploads or database.

Prerequisites:

- WordPress 6.9 or newer, PHP 8.2 or newer, WP-CLI and MySQL/MariaDB with InnoDB.
- A dedicated test database and a user allowed to create tables and triggers.
- `WP_ENVIRONMENT_TYPE=local`, a loopback site URL, and an administrator.
- `DISABLE_WP_CRON=true` so the tests control queue execution themselves.
- `WP_HTTP_BLOCK_EXTERNAL=true`; queue fixtures intercept their HTTP responses.
- The plugin activated with its Composer dependencies installed.

Run from the plugin directory, replacing `/path/to/disposable-wordpress`:

```sh
composer install
npm ci
npm run build
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpcs
npm run test:js -- --runInBand
npm run lint:js
npm run lint:css
wp eval-file tests/integration/queue.php --path=/path/to/disposable-wordpress
wp eval-file tests/integration/content-editing.php --path=/path/to/disposable-wordpress
```

The build must precede PHPStan because the admin bootstrap loads its generated
asset metadata. The queue script exercises Action Scheduler without polling the
admin screen. Content tests verify exact HTML preservation, recovery revisions,
native restoration and rollback after an injected SQL failure.

For an upgrade rehearsal, activate the previous plugin code on that disposable
installation, populate an inventory and record the link IDs, occurrences and exact
post content. Replace only the plugin files with the candidate, without using
WordPress uninstall. The next bootstrap must upgrade `mltr_db_version` to `2`
while retaining those records and content. Existing live sites must not be used
for either this rehearsal or the reset scripts above.

Drop only the dedicated test database through WP-CLI after validation. Keep any
needed reports, then remove the disposable files according to local cleanup rules.
