# AI coding agents

Agents happily import whatever class solves the task in front of them, including another module's models. Cordon Modulith ships [Laravel Boost](https://laravel.com/docs/boost) resources so they don't:

- a **guideline** (`resources/boost/guidelines/core.blade.php`): go through a module's public API, prefer contracts and events, never grow the baseline, run `cordon:verify` after changes that touch several modules;
- the **`cordon-fix-violations` skill** (`resources/boost/skills/`): how to read `cordon:verify --format=json` and fix each rule with before/after examples, without touching the baseline or `depends_on`.

Boost picks both up when you run:

```bash
php artisan boost:install
```

## Without Boost

Add this to your agent instructions (`AGENTS.md`, `CLAUDE.md`, `.cursorrules`...):

```markdown
- Modules may only use another module's public API: Contracts, Events, Data, Enums,
  Exceptions or classes marked #[PublicApi]. Never use another module's models or services.
- After changing code in more than one module, run `php artisan cordon:verify --format=json`
  and fix every violation. Never regenerate the baseline and never add a module to
  depends_on just to silence a violation.
```

The JSON report is designed to be read by tools: every violation has `rule`, `message`, `file`, `line`, `source_module`, `target_module` and `target`.
