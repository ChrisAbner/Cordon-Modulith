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
      - uses: actions/checkout@v4
      - uses: ChrisAbner/Cordon-Modulith@v0.1.0
```

The action installs PHP and your Composer dependencies, then runs `php artisan cordon:verify --format=github`, which adds an annotation to the pull request for every violation.

| Input | Default | Description |
|---|---|---|
| `php-version` | `8.4` | PHP version |
| `working-directory` | `.` | Directory of the Laravel application |
| `format` | `github` | `github`, `text` or `json` |
| `args` | | Extra arguments, for example `--module=Billing` |
| `install-dependencies` | `true` | Set to `false` when an earlier step already ran `composer install` |

If your workflow already sets up PHP and installs dependencies (for example in a test job), add a step instead:

```yaml
      - run: php artisan cordon:verify --format=github
```

## Other CI systems

`cordon:verify` exits with `1` when there are violations, `0` otherwise and `2` on invalid options. Use `--format=json` for a machine readable report:

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
      - uses: actions/checkout@v4
      - uses: ChrisAbner/Cordon-Modulith@v0.1.0
        with:
          args: --module=${{ matrix.module }}
```

`--module` reports the violations a module causes and the dependency cycles it takes part in. The analysis still covers the whole application.
