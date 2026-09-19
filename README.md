# Omeka S Code Snippets

[![codecov](https://codecov.io/gh/ateeducacion/omeka-s-CodeSnippets/branch/main/graph/badge.svg)](https://codecov.io/gh/ateeducacion/omeka-s-CodeSnippets)

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-CodeSnippets%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-CodeSnippets/refs/heads/main/.github/assets/playground-preview-button.svg" alt="Try CodeSnippets in your browser" width="224">
</a><br>
<small><a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-CodeSnippets%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

Manage PHP snippets from the Omeka S admin interface, without editing a theme or writing a module for every small change. Inspired by the WordPress Code Snippets plugin as a functional and UX reference; it does not copy that source.

![Editing a snippet in the Omeka S admin interface](https://raw.githubusercontent.com/ateeducacion/omeka-s-CodeSnippets/refs/heads/main/.github/screenshot.png)

## What it does

- Create, edit, enable, disable, prioritize and delete PHP snippets from the admin
- **Run location** per snippet: everywhere, only in the administration area, or only on the public site
- **Priority** to control execution order
- Editor with PHP syntax highlighting and line numbers (CodeJar, vendored locally, no CDN calls)
- Syntax is checked before a snippet can be activated, so broken code is never stored as active
- Runtime errors are captured and shown next to the snippet instead of breaking the page
- Example snippets included, all inactive
- Safe mode kill switches to recover from a snippet that breaks the site
- Optional REST API at `/api/code_snippets`, reads only unless writes are enabled

Snippets run with the privileges of the Omeka S PHP process. Only `global_admin` may manage them by default; other roles can be allowed in the module configuration, with the consequences described in [who may manage snippets](#who-may-manage-snippets). Read the [security model](#security-model) before using this on a production site.

## Installation

Requirements:

- Omeka S 4.1 or later (`omeka_version_constraint = ^4.1.0`)
- PHP 8.0 or later

Install like any Omeka S module:

1. Copy the `CodeSnippets` directory into `modules/CodeSnippets`, or upload `CodeSnippets-X.Y.Z.zip` from the admin modules page.
2. In Admin → Modules, install **Code Snippets**.
3. `install()` creates the `code_snippet` table and inserts several example snippets, all **inactive**. Disabling the module does **not** delete snippets.

The examples are Omeka S versions of well-known WordPress Code Snippets / WPCode demos (lowercase upload names, hide the admin/user bar, hide the version number, current year in the footer). They do not run until a global administrator activates one. The Omeka S Playground blueprint defines `CODE_SNIPPETS_PLAYGROUND`, which activates **Example: add the current year to the site footer** so you can see snippets working after install.

Docker development (from this repository):

```sh
make upd
```

Open `http://localhost:8080`. Default admin: `admin@example.com` / `PLEASE_CHANGEME`. Enable the module with `make enable-module` if the first boot did not.

## Usage

Open **Admin → Code Snippets** (`/admin/code-snippets`).

- **Examples:** A new install already lists a few inactive snippets (lowercase original filenames, hide the public user bar, hide the Omeka S version in admin, add the current year to the site footer). Activate one to try it.
- **Create:** Add new snippet. Name and PHP code are required. Description, priority, and Active are optional.
- **Edit:** Change any field, then Save. Save and activate stores the snippet and sets it active after syntax validation.
- **Activate / Deactivate:** Use the list or edit screen. State changes are POST requests with CSRF protection.
- **Priority:** Integer. Lower numbers run first. Default is `10`.
- **Run snippet:** Everywhere, administration area only, or site front-end only (same three PHP scopes as the WordPress Code Snippets plugin).
- **Delete:** Opens a confirmation page. POST + CSRF required. Only the selected snippet is removed.

Snippet names do not need to be unique. The identifier is the numeric ID.

The code field is a `<textarea>`. When JavaScript is available, [CodeJar](https://github.com/antonmedv/codejar) plus [codejar-linenumbers](https://github.com/julianpoemp/codejar-linenumbers) turn it into an editor with line numbers. A small built-in tokenizer colors PHP. Omeka’s CKEditor is for HTML, not PHP, so it is not used. Assets load the Omeka way: `$this->assetUrl('…', 'CodeSnippets')` into `headLink` / `headScript`, files under `asset/` and `asset/vendor/`. The form still works if JavaScript fails. Do not wrap snippet code in the `CodeSnippets` namespace.

## Priority

- Lower numeric priority executes earlier.
- Default priority is `10`.
- Negative priorities are allowed.
- When two snippets share a priority, the lower snippet ID runs first (`ORDER BY priority ASC, id ASC`).

## Run location

Each snippet has a run location, stored as `run_scope`:

| Value | UI label | When it runs |
| --- | --- | --- |
| `global` | Run snippet everywhere | Every HTTP request (default) |
| `admin` | Only run in administration area | Routes with Omeka’s `__ADMIN__` flag |
| `front-end` | Only run on site front-end | All other HTTP requests (public site, login, API) |

This matches WordPress Code Snippets: `is_admin()` vs not. CLI and both safe modes still skip every snippet.

## Execution context

Active snippets that match the current run location run at most once per HTTP request.

**Event:** `Laminas\Mvc\MvcEvent::EVENT_ROUTE` (`route`) at priority `-10`.

Omeka’s own route listeners attach at default priority `1` (`redirectToInstallation`, `redirectToMigration`, `redirectToLogin`, `authenticateApiKey`, `prepareAdmin`, `preparePublicSite`, `checkExcessivePost`). Session is bootstrapped on `EVENT_BOOTSTRAP`. Priority `-10` therefore runs after those listeners and before `EVENT_DISPATCH` (where `authorizeUserAgainstController` runs at `1000`). Snippets can still register listeners for dispatch and render.

Snippets do **not** run while constructing `Module`, loading `module.config.php`, or during `install()` / `upgrade()` / `uninstall()`.

Each snippet receives two local variables:

```php
$services  // Omeka service locator (service manager)
$event     // current Laminas\Mvc\MvcEvent
```

Example:

```php
$logger = $services->get('Omeka\Logger');
$logger->info('Hello from CodeSnippets');
```

An opening `<?php` tag is optional and is stripped before storage. Other source is not rewritten.

Function and class declarations in a snippet become global for the rest of that request. Declaring the same function or class twice in one request is an engine-level failure and is not recoverable. `namespace` and `use` are executed as top-level PHP (snippets are not wrapped in a function). `declare(strict_types=1)` is valid as the first statement of a snippet.

## Security model

PHP snippet management is equivalent to executing trusted server-side PHP. A global administrator who can save a snippet can use the database, readable/writable filesystem paths, internal services, network access, and application configuration available to the PHP process.

Only the `global_admin` role may browse, create, edit, activate, deactivate, or delete snippets. That is enforced with Omeka ACL on the controller and on the API adapter (not only by hiding UI). CSRF is required for every state-changing POST. Output is escaped. Request input is never passed to `eval()`.

The REST API applies the same ACL, and additionally keeps writes behind an opt-in setting. See [REST API](#rest-api).

### Who may manage snippets

Out of the box, only `global_admin`. **Admin → Modules → Code Snippets → Configure** can widen that two ways:

- **By role** — tick a role, and every account holding it may manage snippets.
- **By user** — list specific user ids, and only those accounts may, whatever their role. This is how you give one colleague access without promoting everyone who shares their role. A user id appears in the URL of that user's admin page (`/admin/user/42`), and the configuration page echoes each id back with the account it resolves to, so a mistyped number is visible rather than silently granting nobody.

Either way the grant is the same privileges `global_admin` has for snippets, in the admin interface and over the REST API alike.

There is no smaller useful grant. A role that can edit a snippet can run arbitrary PHP inside Omeka, and that code can do anything the web process can, including promoting its own account:

```php
$services->get('Omeka\Connection')
    ->executeStatement("UPDATE user SET role = 'global_admin' WHERE id = 42");
```

So allowing a role here is not a step below global administrator, it is a second route to it — one that does not show up when you audit who holds the `global_admin` role. Grant it only to people you would already trust with the server, and prefer assigning `global_admin` outright when that is what you really mean, because it is the more visible of the two.

The setting stores role identifiers and ignores any it does not recognise, so removing a role from Omeka, or hand-editing the value, cannot grant access to something that no longer exists. If the setting cannot be read at all, snippet management falls back to `global_admin` only.

This module does **not** sandbox PHP. There is no function blacklist, regex filter, or keyword stripper.

See [SECURITY.md](SECURITY.md).

## REST API

Snippets are exposed on the Omeka S REST API as `code_snippets`.

| Operation | Request | Enabled by default |
| --- | --- | --- |
| Search | `GET /api/code_snippets` | Yes |
| Read | `GET /api/code_snippets/:id` | Yes |
| Create | `POST /api/code_snippets` | **No** |
| Update | `PUT` / `PATCH /api/code_snippets/:id` | **No** |
| Delete | `DELETE /api/code_snippets/:id` | **No** |

Only `global_admin` may use any of them. Omeka's API manager checks the ACL before the adapter runs, so this is the same boundary the admin interface uses.

```sh
curl 'https://example.org/api/code_snippets?key_identity=KEY&key_credential=SECRET'
```

A snippet is returned as:

```json
{
  "o:id": 4,
  "o:name": "Example: add the current year to the site footer",
  "o:description": "…",
  "o-module-code-snippets:code": "$shared = $services->get('SharedEventManager');",
  "o-module-code-snippets:priority": 1,
  "o-module-code-snippets:run_scope": "global",
  "o:is_active": true,
  "o:created": "2026-09-18 16:02:11",
  "o:modified": "2026-09-18 16:02:11",
  "o-module-code-snippets:last_error": null
}
```

Writes accept only `name`, `description`, `code`, `priority`, `active` and `run_scope`. Any other key in the request body is discarded, so `id` and the `last_error_*` diagnostics cannot be set by a client. Creating or activating a snippet runs the same syntax check as the admin form and answers `422` when it fails.

### Enabling writes

Writes are **disabled by default** and must be turned on in **Admin → Modules → Code Snippets → Configure**. Until then, `POST`, `PUT`, `PATCH` and `DELETE` answer `403` while reads keep working.

This is deliberate. A write accepts PHP that this module later executes, and Omeka sends API credentials in the query string (`?key_identity=…&key_credential=…`), where they reach web server logs, proxy logs and browser history. Leaving writes off means a leaked key cannot become remote code execution. Turn them on only when you need to provision snippets from a script, and treat the key as a server credential. Both safe modes still apply: snippets created over the API do not run while safe mode is active.

## Safe mode

Recovery is part of the design. If an active snippet breaks HTTP requests, do not delete the database or the module directory.

### Per-request URL safe mode

As an authenticated **global administrator**, add:

```text
?snippets-safe-mode=1
```

Example: `/admin/code-snippets?snippets-safe-mode=1`

- No stored snippet executes during that request.
- The decision is made before the snippet table is queried.
- Anonymous visitors and non-`global_admin` users cannot disable snippets with this parameter.
- The flag is request-scoped. It is not stored in the database, session, or cookies.
- While you stay inside the Code Snippets admin UI, links and forms keep the parameter so you can edit or disable the broken snippet without re-enabling execution on the next click.

The admin list shows: “Safe mode is active. PHP snippets are not being executed.”

### Emergency global safe mode

A snippet may break login, admin routing, or every HTTP request. URL safe mode is then unreachable.

Declare this **before** CodeSnippets runs, at the top of Omeka’s local config file:

```php
<?php
define('OMEKA_CODE_SNIPPETS_SAFE_MODE', true);

return [
    // existing local.config.php array
];
```

**Verified location:** `config/local.config.php` at the Omeka S root (`OMEKA_PATH . '/config/local.config.php'`).

Omeka loads that file from `application/config/application.config.php` via `module_listener_options.config_glob_paths` during `Omeka\Mvc\Application::init()`, after `bootstrap.php` and **before** module `onBootstrap()` and `EVENT_ROUTE`. A `define()` at the top of that file is therefore set before any snippet can run.

Optional equivalent: environment variable `OMEKA_CODE_SNIPPETS_SAFE_MODE=1` (also `true` / `yes`). The constant remains supported.

When the constant is true:

- All snippets are skipped for all requests.
- The snippet table is not queried.
- A snippet cannot override this flag.

Recovery:

1. Enable `OMEKA_CODE_SNIPPETS_SAFE_MODE` in `config/local.config.php`.
2. Reload Omeka.
3. Log in as global administrator.
4. Open Code Snippets.
5. Disable or fix the problematic snippet.
6. Remove or disable `OMEKA_CODE_SNIPPETS_SAFE_MODE`.
7. Verify the public site and admin.

## CLI behavior

Stored snippets are **not** executed when `PHP_SAPI === 'cli'`. That includes `omeka-s-cli` and background jobs that bootstrap Omeka under CLI. There is no CLI command to execute all snippets.

## Import and export

CodeSnippets provides a reusable domain service, `CodeSnippets\Service\SnippetImportExport`, for exporting and importing portable snippet configurations. This service is designed for automation, future CLI integrations, and external tooling without duplicating business rules or coupling to specific interfaces.

### Portable format

Snippets are exported in a versioned envelope format owned by the CodeSnippets module:

```json
{
    "format": "omeka-s-code-snippets",
    "version": 1,
    "snippets": [
        {
            "name": "Example snippet",
            "description": "Example description",
            "code": "$logger = $services->get('Omeka\\Logger');",
            "priority": 10,
            "run_scope": "global",
            "active": false
        }
    ]
}
```

Database-specific fields (such as numeric IDs, creation/modification timestamps, and runtime error tracking) are excluded so snippets remain portable across Omeka S environments.

The portable schema contains:
- `name`: string (required)
- `code`: string (required)
- `description`: string or null (optional, defaults to `null`)
- `priority`: integer (optional, defaults to `10`)
- `run_scope`: string (`global`, `admin`, or `front-end`; optional, defaults to `global`)
- `active`: boolean (optional, defaults to `false`)

Unknown fields at both the document and snippet level are deliberately ignored during import to maintain forward compatibility with future schema revisions and external metadata.

### Service usage

The service is registered in the Laminas service manager as `CodeSnippets\Service\SnippetImportExport`.

```php
/** @var \CodeSnippets\Service\SnippetImportExport $importExport */
$importExport = $services->get(\CodeSnippets\Service\SnippetImportExport::class);

// Export all snippets as an envelope array
$document = $importExport->exportAll();

// Export a single snippet's portable configuration
$snippet = $importExport->export($snippetId);

// Export formatted JSON
$json = $importExport->exportJson();

// Import from an array (single snippet, list of snippets, or envelope document)
$created = $importExport->import($snippetData);
$createdList = $importExport->importMany($document);

// Import directly from a JSON string
$imported = $importExport->importJson($json);
```

### Safety and duplicate handling

- **Canonical validation:** All imports go through `SnippetService::create()`. Active snippets (`"active": true`) undergo PHP syntax validation before persistence and are rejected if syntax errors exist. Inactive snippets with syntax errors can be stored as inactive.
- **Transactional safety:** Multi-snippet imports via `importMany()` run inside a database transaction. If any active snippet fails syntax validation or persistence, the entire batch is rolled back and no snippets from that import are stored.
- **Duplicate handling:** Snippet names in Omeka S CodeSnippets are not unique identifiers. Importing snippets always creates new snippets, even if a snippet with the same name already exists in the database.

## Error handling

Each snippet runs in its own `try/catch (\Throwable $e)`. A recoverable error:

- is not shown to public visitors
- is logged through `Omeka\Logger` with snippet ID, name, throwable class, message, and line (not the snippet body)
- is stored on the snippet as last error type, message, line, and timestamp
- does not stop later snippets

Successful runs do not write to the database.

`catch (\Throwable $e)` cannot recover from every PHP failure, including:

- `exit` / `die`
- memory exhaustion
- hard process timeouts
- some engine-level fatals (for example redeclaring a function or class)
- external process termination

Those cases are why emergency safe mode is mandatory.

## Uninstall

Uninstall is destructive: it **drops** the `code_snippet` table and deletes every stored snippet. Disabling the module does not.

## Architectural decisions

1. **Dedicated table** — an arbitrary collection of PHP source does not belong in `Omeka\Settings`. `code_snippet` is created through `Omeka\Connection` (Doctrine DBAL), matching modules such as CAS. Doctrine entities were not added.
2. **global_admin only** — snippet management is server-side PHP execution. Omeka’s `AclFactory` already allows `global_admin` every privilege; the module still registers an explicit allow list and never allows editor, reviewer, author, site_admin, or anonymous.
3. **eval() in one class** — `SnippetEvaluator` is the only `eval()` call. Code reaches it only after submit → validate → store → load active → `SnippetExecutor`.
4. **No fake sandbox** — blacklists and regex filters are incomplete and misleading.
5. **Lifecycle event** — `route` at priority `-10`, after core route listeners (priority `1`) and before dispatch.
6. **Priority** — lower runs first; ID is the stable tiebreaker.
7. **Variables** — `$services` and `$event` only (static evaluator, no `$this`).
8. **CLI skip** — default recovery path that does not depend on HTTP.
9. **Two safe modes** — request URL (authenticated global_admin) and process-wide constant/env.
10. **Throwable per snippet** — isolation, with documented unrecoverable cases.
11. **Uninstall drops the table** — disable does not.
12. **Example snippets on install** — inactive Omeka S versions of common WordPress Code Snippets demos. `CODE_SNIPPETS_PLAYGROUND` activates the current-year example in the playground.

## Development

```sh
make lint
make test
make test-coverage
make i18n
make package VERSION=1.0.0
```

## License

GNU GPL-3.0-or-later. See [LICENSE](LICENSE).
