# Run only the tests the change can affect

`cordon:affected` lists the modules that a change can affect, and with `--tests` the test folders to run. A pull request that touches `Catalog` then runs the tests of `Catalog` and of the modules that depend on it, and skips the rest.

```bash
php artisan cordon:affected --since=origin/main
```

```text
Modules affected by the changes since origin/main:
  Catalog  changed
  Billing  depends on Catalog
  Orders  depends on Billing
```

`--since` takes any git reference. The changes are what was committed since your branch forked from it (`git diff <ref>...HEAD`), plus what is staged, modified or not tracked yet, so it works the same in CI and on a laptop. A module is affected when one of its files changed, deleted files included, or when it depends on an affected module, directly or through others: the graph is the one of [`cordon:impact`](../guide/explore#what-can-a-change-to-this-module-affect).

With `--format=json`, `version` is the version of the format (`1`: new fields are added without changing it), `since` the reference, `changed` lists the modules that have changed files, and `affected` every module with the `reason` (`changed`, `depends_on`, `parent` for a module that contains an affected nested one, or `nested` for a nested module of any affected module, the siblings of an affected nested module included: it can list one that is not really affected, but never misses a test) and the module it was reached `through` (`null` for a changed module). With `--tests`, `tests` lists the folders. For example:

```json
{
  "version": 1,
  "since": "origin/main",
  "changed": ["Catalog"],
  "affected": [
    {"module": "Catalog", "reason": "changed", "through": null},
    {"module": "Billing", "reason": "depends_on", "through": "Catalog"}
  ]
}
```

## The folders to test

```bash
vendor/bin/cordon affected --since=origin/main --tests
```

```text
tests/Catalog
tests/Billing
app/Modules/Billing/tests
```

One folder per line, relative to the project root, only the ones that exist. For each affected module they are:

- the folders in its `tests` setting, when it has one: `'Catalog' => ['tests' => ['tests/Unit/Pricing', 'tests/Feature/Catalog']]`. `'tests' => []` means the module has no tests of its own.
- otherwise `tests/<Module>` (`tests/Billing/Invoices` for the nested module `Billing.Invoices`) and the `tests` folder inside the module.

A changed file under one of those folders counts as a change of its module, so a pull request that only edits a test runs it.

The code outside the modules (`host.paths`: `app`, `routes` and `database` by default) counts as the module `host`. A change there affects only `host`, because no module depends on it, and `host` has no test folder unless you list one: `'host' => ['tests' => ['tests/Feature']]`.

## In CI

```yaml
name: Affected tests

on: pull_request

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          fetch-depth: 0 # the comparison needs the history of the base branch
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --prefer-dist
      - name: Run the tests of the affected modules
        run: |
          folders=$(vendor/bin/cordon affected --since=origin/${{ github.base_ref }} --tests)
          if [ -z "$folders" ]; then
            echo "No module with tests is affected."
            exit 0
          fi
          vendor/bin/pest $folders
```

Keep the `[ -z ... ]` guard: `pest` with no path runs the whole suite, which is the opposite of what an empty list means. And keep a full run on the main branch (and, if you want, on a schedule), because this list only follows the dependencies Cordon sees.

## What it does not see

- Only references Cordon analyses count. A dependency through a container binding, a string class name or a route does not make a module depend on another.
- A change outside the modules and the host, such as `composer.json`, `config/cordon.php`, a view or a migration outside `host.paths`, affects no module. Run everything when those change.
- With a shallow clone git has no history to compare with, so the command fails with exit code `2` (`Cannot compare with [origin/main]: not a git repository or the reference does not exist.`). Fetch the history (`fetch-depth: 0`) or the base branch.
- A file that does not parse hides its references. The command says so on stderr, and some dependents may be missing.
- If a whole module folder is deleted, the module no longer exists and is not listed. Check what depended on it with `cordon:verify`.
