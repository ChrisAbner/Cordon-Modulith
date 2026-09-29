# Contributing

Thanks for helping make module boundaries in Laravel verifiable.

## Setup

```bash
git clone https://github.com/ChrisAbner/Cordon-Modulith.git cordon-modulith && cd cordon-modulith
composer install
composer check   # pint --test, phpstan, pest
composer bench   # analyses a synthetic project of 1,000 files; fails above 10 s
```

`php tests/Benchmark/run.php 5000 50` runs a larger benchmark (files, modules, optional time budget in seconds).

## Pull requests

- One topic per pull request, with tests. Bug fixes start with a failing test.
- New detection behaviour needs a fixture under `tests/Fixtures` that reproduces a real project layout.
- Run `composer format` before pushing. `tests/Fixtures` is excluded from Pint on purpose, because tests assert line numbers.
- Changes to anything in `src/Contracts`, the config file shape, the baseline format or command options are public API changes: open a discussion or an ADR in `docs/adr` first.
- Keep false positives at zero. A rule that reports correct code is a bug.

## Reporting bugs

Please open a pull request with a failing test or fixture, even if you don't have the fix. Pull requests written with the help of a coding agent are welcome; see [AGENTS.md](AGENTS.md).

## Security

Please report security issues privately; see [SECURITY.md](SECURITY.md). Everyone taking part in the project follows the [code of conduct](CODE_OF_CONDUCT.md).
