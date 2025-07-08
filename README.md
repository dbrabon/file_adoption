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
  `public://`) that should be skipped when scanning. The default configuration
  skips directories such as `css/*`, `js/*`, `private/*`, `webforms/*`,
  `config_*`, `media-icons/*`, `php/*`, `styles/*`, `asset_injector/*`,
  `embed_buttons/*`, and `oembed_thumbnails/*`.
- **Enable Adoption** – When checked, cron will automatically adopt orphaned
  files using the configured settings.
- **Items per cron run** – Maximum number of files processed and displayed per
  scan or cron run. Defaults to 20.
- **Ignore Symlinks** – When enabled, symbolic links are skipped during scanning,
  preventing loops or slowdowns caused by symlinks.
  Symlinks discovered during scanning are still listed under the "Symlinks"
- **Cron Frequency** – How often cron should run file adoption tasks. Options
  include every cron run, hourly, daily, weekly, monthly, or yearly.
- **Verbose Logging** – When enabled, additional debug information is written to
  the log during scans and adoption. This is off by default. Adoption success
  messages are only recorded when verbose logging is enabled. When enabled,
  each directory encountered during cron scans is also logged.

Changes are stored in `file_adoption.settings`.

## Cron Integration

Scanning occurs exclusively during cron runs. The `Cron Frequency` setting
controls how often `hook_cron()` invokes the `FileScanner` service. Each run
records its totals to state so the configuration page can report the last
execution. When **Enable Adoption** is disabled the run also populates the
`file_adoption_orphans` table with any discovered orphans. When adoption is
enabled, files are registered immediately and the table remains empty.

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

## Uninstall

Uninstalling the module removes all of its configuration and drops the
`file_adoption_orphans` table so no leftover data remains.
