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
      - uses: ChrisAbner/Cordon-Modulith@v0.4.0
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

`cordon:verify` (and `vendor/bin/cordon verify`) exits with:

| Code | When |
|---|---|
| `0` | No violations. Warnings (rules set to `warn`) and stale baseline entries do not fail on their own. |
| `1` | Violations of rules set to `error`; no modules found (`--allow-empty` passes while a project has none yet); a stale baseline entry with `--fail-on-stale`; a file that does not parse with `--fail-on-parse-error`. |
| `2` | Invalid options or an invalid `config/cordon.php`. Nothing is analysed. |

Use `--format=json` for a machine readable report. It starts with `"version": 1` and follows the schema in `resources/schemas/report-v1.schema.json`; each violation links to its rule's documentation in `docs_url`. New optional fields may appear in a minor release without changing the version:

```bash
php artisan cordon:verify --format=json > cordon-report.json
```

## Other report formats

`--format` also writes reports other CI tools read:

| Format | For |
|---|---|
| `gitlab` | GitLab Code Quality (`artifacts: reports: codequality: cordon.json`). Errors are major issues, warnings minor. The fingerprint does not change when the code above a violation moves. |
| `junit` | Any CI that shows JUnit results: one test case per checked module (with `--module` or paths, only those), failing with its errors; `failures` counts the failing modules. Warnings and files that do not parse go to the output. |
| `checkstyle` | Jenkins, reviewdog and other Checkstyle readers. |

In GitLab and Checkstyle, violations without a file, such as cycles, are reported at `config/cordon.php`, where `depends_on` and `listeners` are set; JUnit lists them under their module, without a location. A file that does not parse is a warning (`cordon.parse_error`) in GitLab and Checkstyle. Files outside the project root, such as the packages of a monorepo, are written with `../`, so reports and baselines are the same on every machine.

```yaml
# .gitlab-ci.yml
cordon:
  script:
    - vendor/bin/cordon verify --format=gitlab > cordon.json
  artifacts:
    when: always
    reports:
      codequality: cordon.json
```

## One job per module

Teams that own modules can check only theirs:

```yaml
    strategy:
      matrix:
        module: [Billing, Catalog, Orders]
    steps:
      - uses: actions/checkout@v7
      - uses: ChrisAbner/Cordon-Modulith@v0.4.0
        with:
          args: --module=${{ matrix.module }}
```

`--module` reports the violations a module causes and the dependency cycles it takes part in. The analysis still covers the whole application.

## Checking some paths only

`cordon:verify` takes files and directories: `vendor/bin/cordon verify app/Modules/Billing`. The whole project is still analysed, so cycles and the baseline work as usual, but only the violations of those paths are reported, plus the cycles their modules take part in. Relative paths are read from the project root. See [check boundaries before each commit](https://chrisabner.github.io/Cordon-Modulith/recipes/pre-commit).

## Keeping the baseline small

`--fail-on-stale` makes a baseline entry that no longer matches the code fail the build, so fixed violations are removed from the baseline in the same pull request (`--prune-baseline`). See [shrinking the baseline](https://chrisabner.github.io/Cordon-Modulith/guide/baseline#shrinking-the-baseline).

## Files that do not parse

A file that does not parse is skipped, so its references are not checked. Every format reports it: `text` as a comment, `json` under `parse_errors` and `github` as a warning annotation on the file. The job still passes. Add `--fail-on-parse-error` to fail it instead, for example with the action's `args` input.
