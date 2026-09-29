# Pull request: rama a `main`

- **Rama origen:** `claude/cordon-modulith-naming-1w2srv`
- **Destino:** `main`

## Título

```text
Cordon Modulith 0.1.0: verifiable module boundaries for Laravel
```

## Descripción (pegar tal cual)

````markdown
## Summary

First release candidate of **Cordon Modulith** (`chrisabner/cordon-modulith`): a Laravel dev dependency that statically verifies the boundaries between the modules of an application. It parses the code with nikic/php-parser (analysed code is never loaded), maps class references to modules and runs rules. `origin/main` only has the initial commit, so this PR brings the whole project.

### What is in 0.1.0

- **Commands:** `cordon:verify` (`text`, `json` and `github` formats, `--module`, `--generate-baseline`, `--no-baseline`), `cordon:modules` and `cordon:docs` (Mermaid dependency diagrams, a canvas per module, event inventory).
- **Module resolvers:** plain namespaces (`app/Modules`), nwidart/laravel-modules and InterNACHI/modular, with auto-detection.
- **Rules:** `internal_access`, `undeclared_dependency`, `cycles`, plus custom rules (classes implementing `Cordon\Contracts\Rule` in the `rules` config).
- **Public API model:** public namespaces, `#[PublicApi]`, `#[Internal]`, per-module `public` list and `open` modules.
- **Baseline** for gradual adoption in existing projects.
- **Integrations:** Pest expectation `toRespectBoundaries()`, PHPStan rule `cordon.internalAccess`, reusable GitHub Action (`action.yml`), Laravel Boost guideline and the `cordon-fix-violations` skill.
- **Docs and quality:** VitePress documentation site (`docs-site/`, deployed by `docs.yml`), ADRs 0001-0009, real-project fixtures, an analysis benchmark (`composer bench`, 1,000 files under 10 s) and CI (`tests.yml`).

See [CHANGELOG.md](CHANGELOG.md) for the full list.

### Decisions

ADRs 0005-0009 (`--module`, Pest expectation, PHPStan rule, `cordon:docs`, custom rules) were implemented as `proposed` and have been **accepted by the maintainer** in this branch, as `AGENTS.md` requires for changes to commands, config and public API.

## Test plan / status

Run locally on PHP 8.4 (Windows) before opening the PR:

- [x] `pest`: 91 passed, 1 skipped (234 assertions)
- [x] `phpstan analyse`: no errors (level 8)
- [ ] `pint --test`: not conclusive on the Windows checkout (it reports `line_ending` on every file because of CRLF); expected to pass on the Linux CI runner
- [ ] PHP 8.3 has **not** been run locally, only configured in PHPStan; the CI matrix covers PHP 8.3/8.4 x Laravel 12/13
- [ ] Manual runs in 3 real projects (nwidart, InterNACHI, `app/Modules`) are still pending and tracked separately as the exit criterion for 0.1

CI on GitHub only runs on `main` and pull requests, so this PR is the first time the three jobs run: `tests`, `quality` (Pint + PHPStan) and `benchmark`.

## How to review

1. Read `README.md` and `CHANGELOG.md` for the scope, then `docs/adr/` for the decisions behind it.
2. Follow the pipeline in `src/`: `Resolvers` -> `Analysis` (`FileCollector`, `PhpParserExtractor`, `Analyzer`) -> `Rules` -> `Baseline` -> `Reporters`. The core is framework-agnostic; only `src/Laravel`, `src/Testing` and `src/PHPStan` use Laravel.
3. Detection behaviour lives in `tests/Fixtures/` (with `expected.json` for the `real-*` ones) and is asserted by `tests/Unit` and `tests/Feature`.
4. Try it: `composer install && composer check`, then `vendor/bin/pest tests/Feature/CommandsTest.php`.
5. `docs-site/` is the public documentation; `docs/plan/` is internal planning (in Spanish) and can be skimmed.

The branch has 14 commits, roughly one per phase, so reviewing commit by commit works well.

## Checklist

- [ ] The three CI jobs are green (`tests`, `quality`, `benchmark`)
- [ ] ADRs 0005-0009 read and accepted
- [ ] README and `docs-site/` reviewed
- [ ] No text claims affiliation with or endorsement by Laravel
- [ ] `CHANGELOG.md` heading changed from `[Unreleased] - 0.1.0` to `[0.1.0] - YYYY-MM-DD` at release time
- [ ] After merging: enable GitHub Pages (Source: GitHub Actions), then tag `v0.1.0`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
````
