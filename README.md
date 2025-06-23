# File Adoption

File Adoption is a utility module for Drupal that scans the public files directory
for files that are not tracked by Drupal's `file_managed` table. Identified
"orphaned" files can be registered as managed file entities.

## Installation

1. Place the module in your Drupal installation, typically using Composer:
   ```bash
   composer require dbrabon/file_adoption
   ```
   **Note:** The module has not yet been published on Packagist. Until it is
   available there, add it as a VCS repository in your project's `composer.json`.
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
  `public://`) that should be skipped when scanning.
- **Enable Adoption** – When checked, cron will automatically adopt orphaned
  files using the configured settings.
- **Items per cron run** – Maximum number of files processed and displayed per
  scan or cron run. Defaults to 20.

Changes are stored in `file_adoption.settings`.

## Cron Integration

When *Enable Adoption* is active, the module's `hook_cron()` implementation runs
the file scanner during cron to register any discovered orphans automatically.

## Manual Scanning

To run a scan on demand:

1. Visit the File Adoption configuration page at `/admin/reports/file-adoption`.
2. Click **Scan Now** to see a list of files that would be adopted.
3. Review the results and click **Adopt** to create the file entities.

## Packagist Webhook

To keep the package on [Packagist](https://packagist.org) in sync with the
repository, enable the **Packagist** service hook on GitHub:

1. Navigate to the repository **Settings** and open **Webhooks**.
2. Click **Add service** and choose **Packagist** from the list.
3. Provide your package name and API token, then save.

Packagist will now fetch updates whenever you push changes.

# file_adoption

## Running Tests

1. Install the module's dependencies, including those in `require-dev`:
   ```bash
   composer install
   ```
2. Execute PHPUnit from the module directory:
   ```bash
   vendor/bin/phpunit
   ```

The tests are located under the `tests/` directory and include kernel tests for the `FileScanner` service and the configuration form.

## License

File Adoption is released under the [GNU General Public License, version 2 or later](LICENSE).
