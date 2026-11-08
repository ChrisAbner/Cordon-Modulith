# Check boundaries before each commit

`cordon:verify` accepts files and directories, relative to the project root. It still analyses the whole project, so cycles and the baseline are checked as usual, but it only reports the violations of those paths, plus the cycles their modules take part in. That makes it fast and quiet enough for a pre-commit hook.

```bash
git diff --cached --name-only --relative --diff-filter=ACMR -z -- '*.php' | xargs -0 vendor/bin/cordon verify
```

`--relative` gives paths from the current directory, so run it from the Laravel project root (it may be a subdirectory of the repository). `-z` with `xargs -0` keeps file names with spaces whole, and `R` includes renamed files.

With no PHP file staged, the command gets no paths and checks everything; skip it in that case, as the hooks below do.

## Plain git hook

```bash
#!/bin/sh
# .git/hooks/pre-commit (make it executable)
# Git runs hooks from the repository root. If the Laravel project is in a
# subdirectory, go there first, for example: cd backend || exit 1
[ -z "$(git diff --cached --name-only --relative --diff-filter=ACMR -- '*.php')" ] && exit 0
git diff --cached --name-only --relative --diff-filter=ACMR -z -- '*.php' | xargs -0 vendor/bin/cordon verify
```

## CaptainHook

```json
{
    "pre-commit": {
        "enabled": true,
        "actions": [
            {
                "action": "vendor/bin/cordon verify {$STAGED_FILES|of-type:php}",
                "conditions": [
                    { "exec": "\\CaptainHook\\App\\Hook\\Condition\\FileStaged\\OfType", "args": ["php"] }
                ]
            }
        ]
    }
}
```

## GrumPHP

```yaml
grumphp:
    tasks:
        shell:
            scripts:
                - ["-c", "vendor/bin/cordon verify"]
            triggered_by: [php]
```

GrumPHP's `shell` task does not receive the staged files, so this checks the whole project; it is still fast (about a second per thousand files).

Paths cannot be combined with `--generate-baseline` or `--prune-baseline`: the baseline always covers every module.
