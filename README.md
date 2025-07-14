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
   The first cron run after installation automatically performs a full scan of
   `public://`. Run `drush cron` if you want results immediately.
3. Navigate to **Administration → Reports → File Adoption** (`/admin/reports/file-adoption`)
   to configure settings or review the most recent scan results. The page no
   longer runs a scan automatically.

## Configuration

The configuration form offers the following options:

- **Ignore Patterns** – Comma or newline separated patterns relative to
  `public://` that should be skipped when scanning. Patterns may contain simple
  wildcards (`*`, `?`) which are automatically converted to regular
  expressions when the form is saved. The default configuration skips
  directories such as:

  ```
  public://asset_injector/.*
  public://config_.*
  public://css/.*
  public://embed_buttons/.*
  public://js/.*
  public://media-icons/.*
  public://oembed_thumbnails/.*
  public://php/.*
  public://private/.*
  public://styles/.*
  public://webforms/.*
  public://\..*
  ```

- Pattern matching is case-insensitive.
- **Enable Adoption** – Automatically adopts up to the number in **Items per adoption batch** during each cron run using the configured settings.
- **Items per adoption batch** – Maximum number of files adopted or displayed per
  scan or cron run. All discovered orphans are saved regardless of this
  limit. Defaults to 20.
- **Adopt as Temporary** – When checked, newly adopted files are saved as
  temporary. Unchecked files become permanent immediately.
- **Ignore Symlinks** – When enabled, symbolic links are skipped during scanning,
  preventing loops or slowdowns caused by symlinks.
  Symlinks discovered during scanning are still listed under the "Symlinks"
- **Directory Depth** – Maximum directory depth displayed under "Directories".
  Directories deeper than this setting are omitted from the summary but files
  within them can still be adopted. Valid range is 1–9 and the default is 9.
- **Full-scan interval (hours)** – Number of hours between automatic full scans.
    Set to `0` to run on every cron run. The default is 24 hours.
- **Verbose Logging** – When enabled, additional debug information is written to
  the log during scans and adoption. This is off by default. Adoption success
  messages are only recorded when verbose logging is enabled. When enabled,
  each directory encountered during cron scans is also logged.

The configuration page also shows details gathered from the most recent scan:

- **Directories** – Lists every directory discovered. Directories marked as
  ignored are annotated with “(ignored)” and any ignored file names appear in
  parentheses. This information is read from the `file_adoption_index` table.
- **Add to Managed Files** – Displays the files scheduled for adoption. Entries
  are pulled directly from the `file_adoption_index` table after filtering out
  ignored directories and patterns.
- **Run full scan** – Button at the bottom of the form that immediately
  executes a full cron-style scan of the public files directory.

Changes are stored in `file_adoption.settings`.

## Cron Integration

Scanning occurs exclusively during cron runs. Upon installation a state flag
causes the next cron run to perform an immediate full scan. The *Full-scan interval* setting
controls how often `hook_cron()` invokes the `FileScanner` service. Each run
records its totals to state so the configuration page can report the last
execution. When **Enable Adoption** is disabled the run simply updates the
`file_adoption_index` table with any discovered orphans. When adoption is
enabled, matching files are registered immediately. Every cron run rebuilds the
`file_adoption_index` table so the most current data is always available.

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

Uninstalling the module removes all configuration and drops the
`file_adoption_index` table so no leftover data remains.

### v2.0.0 – 2025‑07‑11

* **Replaced** orphan tracking table – the single `file_adoption_index` table
  now holds everything:
  * `is_managed`  → present in file_managed
  * `is_ignored`  → matches an admin‑defined regex ignore pattern
  * `directory_depth` → number of “/” in the public:// relative path
* Ignore patterns accept full **Perl‑compatible regular expressions** and may
  include simple wildcards which are converted to regex when the configuration
  is saved.
* Cron and the “Adopt Now” button both adopt files by selecting
  `is_managed = 0 AND is_ignored = 0`, up to **Items per adoption batch**.
* The admin UI pulls its *Directories* and *Add to Managed Files* sections
  directly from `file_adoption_index`, so results appear even while a long
  full‑disk scan is still running.
* Database update 10012 will:
  1. Drop the obsolete `file_adoption_orphans` table.
  2. Rename old `ignored`/`managed` fields if present.
  3. Add the new `directory_depth` field.

