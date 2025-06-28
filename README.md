# File Adoption

File Adoption is a utility module for Drupal that scans the public files directory
for files that are not tracked by Drupal's `file_managed` table. Identified
"orphaned" files can be registered as managed file entities.

## Installation

1. Place the module in your Drupal installation using Composer:
   ```bash
   composer require dbrabon/file_adoption
   ```
   Alternatively, copy the module into `modules/custom`.
2. Enable the module from the **Extend** page or with Drush:
   ```bash
   drush en file_adoption
   ```
3. Navigate to **Administration → Reports → File Adoption** (`/admin/reports/file-adoption`)
   to configure and run scans.

## Configuration

The configuration form offers the following options:

- **Ignore Patterns** – Comma or newline separated patterns (relative to
  `public://`) that should be skipped when scanning. The default list now
  includes `webform/*` alongside directories such as `asset_injector/*`.
- **Enable Adoption** – When checked, cron will automatically adopt orphaned
  files using the configured settings.
- **Items per cron run** – Maximum number of files processed and displayed per
  scan or cron run. Defaults to 100 and is capped at 5000.
- **Folder depth** – Limits how deep into subdirectories the preview will scan.
  Set to 0 for no limit.

Changes are stored in `file_adoption.settings`.

## Cron Integration

When *Enable Adoption* is active, the module's `hook_cron()` implementation runs
the file scanner during cron to register any discovered orphans automatically.
Each cron run adopts at most the number of items specified by **Items per cron
run**, so large backlogs may take multiple executions to finish. Scanning
progress is stored between cron runs so that only a portion of the public files
directory is processed on each execution. After the entire directory has been
scanned the offset resets and the cycle begins again. Cron also processes any
pending preview tasks and will resume interrupted scans automatically. Directory
inventories used by the preview are cached for 24 hours.
Directories that contain no orphaned files are also cached and skipped on
subsequent scans to reduce workload. Use the **Clear Cache** button on the
configuration form to reset these caches when needed.

## Manual Scanning

To run a scan on demand:

1. Visit the File Adoption configuration page at `/admin/reports/file-adoption`.
2. Click **Quick Scan** (or **Batch Scan**) to begin building a preview.
3. The preview loads asynchronously in three phases: directories, example files
   and file counts. Once the scan is finished, click **Adopt** to register the
   orphaned files.

## Items per Run

The `items_per_run` setting defines how many orphaned file URIs may be
**collected** during each batch step. The scanner still checks every entry in
the public files directory, so the progress counter can jump by thousands when
most items are ignored or already managed. The default limit is 100 and may be
increased up to 5000. Larger values reduce the number of requests needed to
finish a scan while lower values keep each step shorter.

## Batch Step Size

The scan operates in short steps so progress can be reported to the browser.
`items_per_run` only dictates how many *orphaned* URIs are accumulated before a
step finishes. Because regular files are skipped, a step may examine thousands
of paths to find the next batch of orphans. Larger limits reduce the number of
HTTP requests needed to complete a scan, while smaller limits keep each step
brief for slower environments. Quick scanning is attempted first and the batch
process is used when the quick method exceeds its time limit.

## Quick Scanning

During manual scans the module first attempts to inspect files without starting a batch job. This quick-scan mode runs for up to 20 seconds by default. If all files are processed before the limit is reached, results appear immediately. Otherwise the form falls back to the batch process so scanning can continue in the background.

The 20 second limit can be changed by setting the `FILE_ADOPTION_SCAN_LIMIT` environment variable.

## Scanning Workflow

Building the preview happens in three stages that may span multiple page loads or cron runs:

1. **Directory inventory** – lists folders up to the configured *Folder depth*.
2. **Example discovery** – finds a sample file within each folder.
3. **Counting files** – totals files per folder while detecting orphaned entries.

Each stage stores its progress so that scanning can resume later without repeating work.

## Handling Symbolic Links

The scanner now follows symbolic links only once when traversing the public
files directory. Any circular link that resolves back to a previously visited
location is skipped so scanning cannot loop indefinitely. This applies to quick
scans, batch scans and the preview step.


## Drush Scanning

Scanning can also be performed from the command line. The `file_adoption:scan`
command mirrors the form and cron functionality. Use `--adopt` to immediately
register orphaned files and `--limit` to control how many items are processed.
When no limit is specified, the value from *Items per cron run* is used.

```bash
# Preview orphaned files without adopting
drush file_adoption:scan

# Adopt up to 50 files
drush file_adoption:scan --adopt --limit=50
```

## Removing Duplicate File Entries

Duplicate `file_managed` rows occasionally accumulate when the same file is
imported multiple times. Clean these up with the included Drush command:

```bash
drush file_adoption:dedupe
```

The command keeps the newest database record for each URI and deletes any older
duplicates.

## Development

This module requires **PHP 8.2** or later.

1. Install the development dependencies with Composer. Drupal core packages
   listed under `require-dev` will be installed automatically:

   ```bash
   composer install
   ```

2. Once the dependencies are installed, run the test suite using the provided
   PHPUnit configuration:

   ```bash
   vendor/bin/phpunit --configuration phpunit.xml.dist
   ```

   If the module is installed inside an existing Drupal site, you may instead
   run `vendor/bin/phpunit` from the Drupal root using that installation's
   `phpunit.xml` file.


## License

File Adoption is released under the [GNU General Public License, version 2 or later](LICENSE).
