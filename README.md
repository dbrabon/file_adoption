# file_adoption

## Running Tests

1. Install development dependencies using Composer:
   ```bash
   composer install --dev
   ```
2. Execute PHPUnit from the module directory:
   ```bash
   vendor/bin/phpunit
   ```

The tests are located under the `tests/` directory and include kernel tests for the `FileScanner` service and the configuration form.
