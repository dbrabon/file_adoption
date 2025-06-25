# File Adoption

File Adoption is a utility module for Drupal that scans the public files directory
for files that are not tracked by Drupal's `file_managed` table. Identified
"orphaned" files can be registered as managed file entities.

## Installation

1. Place the module in your Drupal installation using Composer:
   ```bash
   composer require dbrabon/file_adoption:^1.0.6
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
  includes `webform/*` alongside directories such as `asset_injector/*` and
  `webforms/*`.
- **Enable Adoption** – When checked, cron will automatically adopt orphaned
  files using the configured settings.
- **Items per cron run** – Maximum number of files processed and displayed per
  scan or cron run. Defaults to 20 and capped at 500.

Changes are stored in `file_adoption.settings`.

## Cron Integration

When *Enable Adoption* is active, the module's `hook_cron()` implementation runs
the file scanner during cron to register any discovered orphans automatically.
Scanning progress is stored between cron runs so that only a portion of the
public files directory is processed on each execution. After the entire directory
has been scanned the offset resets and the cycle begins again.

## Manual Scanning

To run a scan on demand:

1. Visit the File Adoption configuration page at `/admin/reports/file-adoption`.
2. Click **Scan Now** to see a list of files that would be adopted.
3. Review the results and click **Adopt** to create the file entities.

## Items per Run

The `items_per_run` setting defines how many files are scanned in each batch.
Larger values mean more files are processed before control returns to the
browser, reducing the number of passes needed to complete a scan. The default is
20 but the value can be raised up to 500. Increasing this limit is especially
helpful during manual scans where higher batch sizes dramatically shorten the
time it takes for results to appear.

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
