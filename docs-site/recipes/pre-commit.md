# Check boundaries before each commit

`cordon:verify` accepts files and directories. It still analyses the whole project, so cycles and the baseline are checked as usual, but it only reports the violations of those paths, plus the cycles their modules take part in. That makes it fast and quiet enough for a pre-commit hook.

```bash
vendor/bin/cordon verify $(git diff --cached --name-only --diff-filter=ACM -- '*.php')
```

With no PHP file staged, the command gets no paths and checks everything; skip it in that case, as the hooks below do.

## Plain git hook

```bash
#!/bin/sh
# .git/hooks/pre-commit (make it executable)
files=$(git diff --cached --name-only --diff-filter=ACM -- '*.php')
[ -z "$files" ] && exit 0
exec vendor/bin/cordon verify $files
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
