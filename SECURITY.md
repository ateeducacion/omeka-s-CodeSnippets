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

Only `global_admin` may manage snippets by default. The operator can explicitly grant
equivalent snippet-management privileges to other roles or named users. All such managers
are trusted to execute arbitrary PHP. Other users and anonymous visitors must not be able
to browse, create, edit, activate, deactivate, or delete snippets.

The `eval()` call inside `SnippetEvaluator` is not a vulnerability by itself. It becomes a vulnerability only if an unauthorized input path can reach it. Executing snippets directly from unauthenticated request input is prohibited.

### Standard mode

With no signing key configured (`null`, empty string, or absent), signing is disabled.
The database is part of the trusted computing base. A principal with arbitrary database
write access can potentially modify executable snippet records. Legacy unsigned active
snippets continue executing. This is the default conventional snippet-manager trust model.

### Hardened integrity mode

An operator may configure `code_snippets.signing_key` in Omeka's external configuration,
preferably using an environment-injected secret. The key is never a database setting or
web-form field. A valid key is a string of at least 32 bytes; use 32 cryptographically
random bytes encoded as 64 hexadecimal characters. Exact key bytes are used unchanged.
Explicitly malformed configuration fails closed instead of silently selecting standard mode.

Every active snippet must authenticate with HMAC-SHA256 before reaching `SnippetEvaluator`.
The canonical payload binds ID, name, description, code, priority, active state, and run
scope with a domain/version prefix. A signature cannot be copied to a different ID or used
to activate an unsigned row. Runtime errors and timestamps are outside the signed payload.
Invalid, absent, unsupported, or unverifiable signatures never fall back to execution.

**Scope: direct snippet-table tampering.** Database writes alone are insufficient to forge
new executable snippet state without the signing key, provided the authenticated
administrative write path remains trusted. This feature does not provide authentication
or authorization independent of Omeka S. If an attacker changes wider database state to
impersonate or create an authorized administrator, or changes management grants and then
uses the normal application write path, that compromises a separate trust boundary and
is outside this feature's threat model.

| Attacker capability | Protection provided by this mode |
| --- | --- |
| Direct insertion/modification of `code_snippet` rows, without the key or trusted write access | New executable state cannot authenticate and is skipped |
| Compromise of the signing secret or its configuration/environment | None |
| Filesystem, server, shell, module-code, or arbitrary PHP compromise | None |
| Compromise of Omeka authentication/authorization, or trusted management privileges | None |

Trusted writes through `SnippetService` sign the final active state atomically with its
content. Inactive rows may remain unsigned; deactivation clears signatures in hardened
mode. A deliberate save/activation trusts the reviewed database state. No migration,
background job, or execution path automatically signs existing contents. REST signatures
are not writable or returned; portable imports ignore signatures and exports omit them.

Enabling the mode blocks existing unsigned active snippets until an administrator reviews
and saves/reactivates them. Changing the key blocks old signatures until the same deliberate
review and save. There is one current key, with no previous-key fallback. Restore a database
with its matching external key to retain validation; a different configured key rejects
the signatures. **Removing the key selects standard mode**, including after a restore.
Deployments requiring fail-closed secret injection should configure
`getenv('OMEKA_CODE_SNIPPETS_SIGNING_KEY') ?: false` so a missing secret is a configuration
error. See [configuration and recovery](README.md#optional-database-integrity-signing).

HMAC integrity does not prevent deleting/disabling snippets, database corruption, general
database compromise, denial of service, or replay/rollback of an older signed state with
its valid signature for the same ID. It does not secure operations performed by trusted
PHP on other attacker-controlled data. Filesystem write access, access to the local
configuration or secret-bearing environment, arbitrary PHP execution, shell/server access,
module-code modification, and legitimate snippet-management permissions are outside this
protection. HMAC integrity is not a sandbox: trusted administrators still intentionally
execute arbitrary PHP.

## Report these as vulnerabilities

- Privilege escalation that lets an unauthorized principal manage snippets
- Direct snippet-table tampering that bypasses enabled integrity verification without compromising the external key or trusted administrative write path
- CSRF that allows unauthorized snippet creation, update, activation, deactivation, or deletion
- XSS when rendering snippet names, descriptions, code, or runtime errors
- Any path that evaluates PHP from unauthenticated or unprivileged request input
- URL `?snippets-safe-mode=1` disabling snippets for anonymous users or non-global administrators
- Logging or displaying secrets, passwords, session data, or full snippet bodies in public responses

## Not vulnerabilities

- A trusted global administrator executing PHP that accesses the database, filesystem, services, or network
- The presence of `eval()` in `SnippetEvaluator` after stored, validated, active code is loaded
- Unrecoverable engine failures (`exit`, OOM, some fatals) that require emergency safe mode
