# Security Policy

## Supported Versions

Only the latest version of the module is supported with security updates. `omeka-s-CodeSnippets` follows a rolling release model.

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |
| < 1.x   | :x:                |

## Reporting a Vulnerability

If you discover a security vulnerability in the module, please report it privately and responsibly.

- **Preferred method:** Open an issue marked as `security` and do not include sensitive details.
- **Alternative:** Email the maintainer directly at `ate.educacion@gobiernodecanarias.org`.

Once reported:

- You'll receive an acknowledgment within 3 working days.
- We'll investigate and aim to patch confirmed vulnerabilities within 10 working days.
- If the issue is critical and affects users broadly, we may issue a security advisory and notify on the GitHub releases page.

Do not report security issues via public channels (e.g., Twitter, public issues, discussions).

## Trust model

Arbitrary PHP execution is **intentional functionality**. A global administrator who can save a snippet can run PHP with the privileges of the Omeka S PHP process.

This module does **not** sandbox PHP. There is no function blacklist, regex filter, or keyword stripper.

Only `global_admin` may manage snippets. Lower roles (`editor`, `reviewer`, `author`, `site_admin`, `researcher`) and anonymous users must not be able to browse, create, edit, activate, deactivate, or delete snippets.

The `eval()` call inside `SnippetEvaluator` is not a vulnerability by itself. It becomes a vulnerability only if an unauthorized input path can reach it. Executing snippets directly from unauthenticated request input is prohibited.

## Report these as vulnerabilities

- Privilege escalation that lets a role other than `global_admin` manage snippets
- CSRF that allows unauthorized snippet creation, update, activation, deactivation, or deletion
- XSS when rendering snippet names, descriptions, code, or runtime errors
- Any path that evaluates PHP from unauthenticated or unprivileged request input
- URL `?snippets-safe-mode=1` disabling snippets for anonymous users or non-global administrators
- Logging or displaying secrets, passwords, session data, or full snippet bodies in public responses

## Not vulnerabilities

- A trusted global administrator executing PHP that accesses the database, filesystem, services, or network
- The presence of `eval()` in `SnippetEvaluator` after stored, validated, active code is loaded
- Unrecoverable engine failures (`exit`, OOM, some fatals) that require emergency safe mode
