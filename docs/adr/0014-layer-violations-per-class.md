# ADR 0014: Report layer violations per class

**Status:** accepted

## Context
`layers` reported one violation per file and module depended on, with the module name as target. The baseline (ADR 0004) keys entries by rule, file and target, so once a file was recorded for `Services -> Http`, every new `Http` class that file started to use produced the same key and stayed suppressed. It is the same hole `cycles` had before ADR 0011. The message also named only the two modules, so it did not say which class crossed the layer.

## Decision
Report one violation per file and class depended on, as `internal_access`, `host_access` and `public_api_leak` do. The target is the class (compared without regard to letter case, so `App\Http\Foo` and `app\http\foo` in one file are one violation), and the message names it: `Module [Services] (layer 3) depends on [Http] (layer 1) through App\Http\Requests\StoreUserRequest; a layer may only depend on the layers below it.` Source and target module are unchanged. Listeners are still counted or not by `listeners.depends_on`.

The baseline format does not change: only the target of `layers` entries does. `layers` was added in 0.4, so no released version wrote such entries and the baseline version stays at 2.

## Consequences
- A new class of a layer above, used by a file already in the baseline for another class of that layer, is a new violation.
- Two uses of the same class in one file are one violation.
- More reports: a file that uses several classes of an upper layer gives one for each.
- A baseline generated with `layers` from a development version before 0.4.0 holds module targets that no longer match: regenerate it once with `--generate-baseline`.
- `layers` has no PHPStan rule in 0.4, so nothing changes there.
