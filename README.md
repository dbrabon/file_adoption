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
   to configure and run scans.

## Configuration

The configuration form offers the following options:

- **Ignore Patterns** – Comma or newline separated patterns (relative to
  `public://`) that should be skipped when scanning.
- **Enable Adoption** – When checked, cron will automatically adopt orphaned
  files using the configured settings.
- **Items per cron run** – Maximum number of files processed and displayed per
  scan or cron run. Defaults to 20.
- **Skip symlinks** – Enabled by default to ignore files reached through symbolic links. Disable to include them in scans.

Changes are stored in `file_adoption.settings`.

## Cron Integration

When *Enable Adoption* is active, the module's `hook_cron()` implementation
registers unmanaged files found in the tracking table on each run. Up to the
configured number of items are adopted per invocation so large inventories can
be processed gradually.

## Manual Scanning

To run a scan on demand:

1. Visit the File Adoption configuration page at `/admin/reports/file-adoption`.
2. Click **Scan** to populate the tracking tables.
3. Use **Adopt** to register unmanaged files or **Cleanup** to remove stale records.

## Performance Considerations

Scanning large file trees can take significant time. If manual scans time out,
increase PHP's max execution time in your environment. For very large sites,
prefer running scans via cron so processing occurs in the background. Use the
module's **Items per cron run** setting to control how many files are processed
in each pass.

The **Tracked Files** preview is built from the module's database tables. Use
the filters to view only ignored or unmanaged files.

