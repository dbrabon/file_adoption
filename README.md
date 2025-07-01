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
- **Follow symbolic links** – When checked, files discovered through symbolic
  links are included in scans. Disabled by default.
- **Maximum scan depth** – Limit how many directory levels scans will traverse.
  Set to `0` for no limit.
- **Debug log path** – Optional file path for detailed scan logs.

Changes are stored in `file_adoption.settings`.

## Tracking Tables

Scanning and adoption rely on two tables provided by the module:

- `file_adoption_dir` stores directories discovered during a scan.
  The `ignore` flag marks paths that should be skipped in future scans.
- `file_adoption_file` stores individual files along with their modification
  time, ignore status, adoption state (`managed`) and a reference to the parent
  directory.
  Ignore status for both tables is stored in an `ignore` column and updated
  automatically whenever a path matches or no longer matches the configured
  patterns.

These tables keep a persistent inventory so subsequent scans and cron runs only
process new or changed items.

When the tables are empty, a one-time initialization runs before the first scan.
All URIs from Drupal's `file_managed` table are imported so existing managed
files are tracked and not reported as orphans.
Before every subsequent scan the module again imports URIs from
`file_managed`. This keeps the tracking tables synchronized when new managed
files are created between scans.

## Cron Integration

When *Enable Adoption* is active, the module's `hook_cron()` implementation
checks the `file_adoption_file` table for unmanaged entries. If the tables are
empty cron triggers a scan; otherwise it adopts up to the configured number of
files from the inventory. This allows large inventories to be processed
gradually across multiple cron runs.

## Manual Scanning

To run a scan on demand:

1. Visit the File Adoption configuration page at `/admin/reports/file-adoption`.
2. Click **Scan** to record directories and files in the tracking tables.
3. Use **Adopt** to register unmanaged files or **Cleanup** to purge entries for
   paths that no longer exist.

## Performance Considerations

Scanning large file trees can take significant time. If manual scans time out,
increase PHP's max execution time in your environment. For very large sites,
prefer running scans via cron so processing occurs in the background. Use the
module's **Items per cron run** setting to control how many files are processed
in each pass.

The **Tracked Files** preview lists the tracked files found during the most
recent scan using the configured ignore patterns. Previews display the last
completed scan and may be outdated after changing settings until another scan
runs.

The **Directory Preview** section queries the `file_adoption_dir` table and
groups results by their `ignore` flag into **Tracked** and **Ignored** lists.
Each group displays up to 20 directories and shows the total count when more
paths exist.

## Incremental Scanning

Scanning walks the public files directory in *directories first* order. Each
directory record is created before its files are processed, allowing scans to be
divided into small chunks. Cron and batch scans take advantage of this
incremental approach so large file trees can be processed gradually without
loading every file into memory at once.

## Troubleshooting

- Set `debug_log_path` (defaults to `public://file_adoption_debug.log`) to record detailed scanning and adoption output.
- Review the generated log to follow how directories and files are processed.
- Previews may be stale until another scan is performed.

