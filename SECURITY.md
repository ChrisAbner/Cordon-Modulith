# Security policy

## Supported versions

Cordon Modulith is in its 0.x series. Security fixes are released for the latest minor version only.

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please do not open a public issue. Report it privately through GitHub instead: go to the [Security tab](https://github.com/ChrisAbner/Cordon-Modulith/security) and click **Report a vulnerability**.

Include the Cordon Modulith version, PHP and Laravel versions, and the smallest project or file that reproduces the problem. You will get an answer within 7 days. Once a fix is released, the advisory is published with credit to you unless you prefer otherwise.

## Scope

Cordon Modulith is a development dependency. It parses your code statically and never loads or executes the classes it analyses. It does evaluate your project's config files (`config/cordon.php`, `config/modules.php`, `config/app-modules.php`) to find your modules, so treat those files as code.
