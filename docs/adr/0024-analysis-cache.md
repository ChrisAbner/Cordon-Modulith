# ADR 0024: The analysis cache

**Status:** accepted (2026-11-08, plan approved by the maintainer: everything seen goes in)

> The maintainer approved the plan for 0.5 on 2026-11-08 with the decision that everything seen goes in. The file format and what invalidates it are the plan's, as the code implements them.

## Context
Parsing every file on every run is what `cordon:verify` spends its time on. A pull request that touches a few files should not parse the other thousands.

## Decision
- `cache` in the config, `null` by default (no file is read or written). A path, relative to the project root or absolute, turns it on. `cordon:verify --no-cache` neither reads nor writes it for one run.
- **What it holds.** The `FileAnalysis` that the extractor returned for each file, never a rule result, so changing `depends_on`, a severity or the baseline needs no new cache. `CachedExtractor` decorates the extractor: it asks the wrapped one only for files whose content changed, keyed by the sha1 of the content (touching or checking out a file does not invalidate it).
- **File format.** JSON: `{"version": 1, "fingerprint": "...", "files": {"<path relative to the project root>": {"hash": "...", "analysis": {...}}}}`. Paths are relative so a cache survives a project that moves. The file is written next to its target and renamed over it, so a run that reads while another writes sees the old file or the new one, and it is rewritten only when something changed. Only the entries a run used are saved, so deleted files drop out. `cordon:docs` analyses the modules alone and keeps the entries it does not use (the host files) until the next full run.
- **Fingerprint.** The sha1 of: the installed versions of Cordon, `nikic/php-parser`, `phpstan/phpdoc-parser` and `illuminate/support` (its inflector names the tables of a model), the PHP version, and the extractor options: `docblocks`, whether the `tables` rule is on, and the class of the extractor, since a project can bind its own. A cache with another fingerprint is a normal miss and is replaced. A new extractor option is added to the options where the extractor is built, and nowhere else.
- **Failure.** A file that cannot be read, is damaged or has another `version` is a warning (`Cordon cache at <path> is unreadable; analysing without it.`) and the run goes on without it. A damaged entry is a miss for that file. The cache can make a run faster, never different: the report is byte-identical cold, warm or disabled, which the tests assert on the fixtures.
- **Trust.** The cache has no integrity check: an entry is believed as it is. A cache written by a job that is not trusted (a pull request from a fork) could hide violations, so CI should restore it only from protected branches and from the repository's own pull requests, and pass `--no-cache` when in doubt. The CI guide says so.

## Consequences
- A project that leaves `cache` as `null` is unchanged.
- Updating Cordon, php-parser, phpdoc-parser, illuminate/support or PHP starts a new cache by itself. Changing any other dependency does not, and the extractor does not depend on them.
- The format has a `version`. A future change bumps it, and older files are ignored with the unreadable-cache warning.
- The fingerprint depends on Composer's installed versions: a project run without Composer's runtime metadata gets `unknown` for those and does not invalidate on updates.
