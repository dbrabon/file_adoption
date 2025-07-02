# File Adoption

File Adoption is a utility module for Drupal that scans the public files directory
for files that are not tracked by Drupal's `file_managed` table. Identified
"orphaned" files can be registered as managed file entities.

## Installation

1. Place the module in your Drupal installation, typically using Composer:
   ```bash
   composer require dbrabon/file_adoption
   ```
   or by copying the module into `modules/custom`.
2. Enable the module from the **Extend** page or with Drush:
   ```bash
   drush en file_adoption
   ```
3. Navigate to **Administration → Reports → File Adoption** (`/admin/reports/file-adoption`)
   to configure settings or review the most recent scan results. The page no
   longer runs a scan automatically.

## Configuration

The configuration form offers the following options:

- **Ignore Patterns** – Comma or newline separated patterns (relative to
  `public://`) that should be skipped when scanning.
- **Enable Adoption** – When checked, cron will automatically adopt orphaned
  files using the configured settings.
- **Items per cron run** – Maximum number of files processed and displayed per
  scan or cron run. Defaults to 20.
- **Ignore Symlinks** – When enabled, symbolic links are skipped during scanning,
  preventing loops or slowdowns caused by symlinks.

Changes are stored in `file_adoption.settings`.

## Cron Integration

When *Enable Adoption* is active, the module's `hook_cron()` implementation runs
the file scanner during cron to register any discovered orphans automatically.
If adoption is disabled, cron still records the orphaned files it finds in the
`file_adoption_orphans` table so they can be reviewed later.
The configuration page now only reads these saved results and never performs a
scan automatically. Scans are triggered via cron or by clicking **Scan Now** on
the configuration page.

## Manual Scanning

To run a scan on demand:

1. Visit the File Adoption configuration page at `/admin/reports/file-adoption`.
2. Click **Scan Now** to see a list of files that would be adopted.
3. Review the results and click **Adopt** to create the file entities.

# file_adoption

## Running Tests

These tests rely on Drupal's core testing environment. Make sure your Drupal
installation includes the `drupal/core-dev` package. Tests should be executed
from the Drupal project root so that Drupal's PHPUnit configuration is used.

From the module directory you can run:

```bash
../vendor/bin/phpunit -c core modules/custom/file_adoption
```

The tests are located under the `tests/` directory and include kernel tests for
the `FileScanner` service and the configuration form.
