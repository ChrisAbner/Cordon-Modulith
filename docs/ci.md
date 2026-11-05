# Continuous integration

## GitHub Actions (reusable action)

```yaml
# .github/workflows/cordon.yml
name: cordon
on: [pull_request]
jobs:
  cordon:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: ChrisAbner/Cordon-Modulith@v0.3.1
```

The action installs PHP and your Composer dependencies, then runs `vendor/bin/cordon verify --format=github`, which adds an annotation to the pull request for every violation. It does not boot your application, so the job needs no `.env`, database or `APP_KEY` (see [without booting Laravel](#without-booting-laravel)). Set `artisan: true` to run `php artisan cordon:verify` instead.

| Input | Default | Description |
|---|---|---|
| `php-version` | `8.4` | PHP version |
| `working-directory` | `.` | Project root, where `composer.json` is |
| `format` | `github` | `github`, `text` or `json` |
| `args` | | Extra arguments, for example `--module=Billing` |
| `install-dependencies` | `true` | Set to `false` when an earlier step already ran `composer install` |
| `artisan` | `false` | Set to `true` to run `php artisan cordon:verify`, which boots the application |

If your workflow already sets up PHP and installs dependencies (for example in a test job), add a step instead:

```yaml
      - run: vendor/bin/cordon verify --format=github
```

## Without booting Laravel

`vendor/bin/cordon` runs the same commands as artisan without booting the application: `verify`, `modules` and `docs`, with the same options and exit codes (`cordon:verify` and the other artisan names work too). Run it from the project root.

```bash
vendor/bin/cordon verify --format=github
vendor/bin/cordon modules
```

It reads `config/cordon.php` (plus `config/modules.php` and `config/app-modules.php` when present) the way the [PHPStan rule](./phpstan) does: helpers such as `base_path()` and `env()` work, but `.env` is not loaded, so `env()` only sees real environment variables. A config file that cannot be evaluated this way is reported on stderr and its defaults are used.

Use artisan when the configuration needs the booted application, or when custom rules need services that only the application registers.

## Other CI systems

`cordon:verify` (and `vendor/bin/cordon verify`) exits with `1` when there are violations or no modules are found (`--allow-empty` passes while a project has none yet), `0` otherwise and `2` on invalid options. Use `--format=json` for a machine readable report:

```bash
php artisan cordon:verify --format=json > cordon-report.json
```

## One job per module

Teams that own modules can check only theirs:

```yaml
    strategy:
      matrix:
        module: [Billing, Catalog, Orders]
    steps:
      - uses: actions/checkout@v7
      - uses: ChrisAbner/Cordon-Modulith@v0.3.1
        with:
          args: --module=${{ matrix.module }}
```

`--module` reports the violations a module causes and the dependency cycles it takes part in. The analysis still covers the whole application.

## Files that do not parse

A file that does not parse is skipped, so its references are not checked. Every format reports it: `text` as a comment, `json` under `parse_errors` and `github` as a warning annotation on the file. The job still passes. Add `--fail-on-parse-error` to fail it instead, for example with the action's `args` input.
