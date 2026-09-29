# Changelog

All notable changes to Cordon Modulith are documented here. The project follows [Semantic Versioning](https://semver.org) from 1.0.0; before that, minor versions may contain breaking changes.

## [Unreleased] - 0.1.0

### Added
- `cordon:verify` command with `text`, `json` and `github` output formats.
- `cordon:modules` command to inspect the detected modules.
- Module resolvers for plain namespaces, nwidart/laravel-modules and InterNACHI/modular, with auto-detection.
- Static dependency extraction with nikic/php-parser (the analysed code is never loaded).
- Rules: `internal_access`, `undeclared_dependency`, `cycles`.
- Public API model: public namespaces, `#[PublicApi]`, `#[Internal]`, per-module `public` list and `open` modules.
- Baseline file for gradual adoption (`--generate-baseline`, `--no-baseline`).
- Laravel Boost guideline for AI coding agents.
