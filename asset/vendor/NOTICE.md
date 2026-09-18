# Vendored editor assets

Omeka S ships CKEditor for HTML, not a PHP editor. These libraries live under
`asset/vendor/` and are loaded with the same helpers as core (`assetUrl` +
`headLink` / `headScript`), e.g. OpenSeadragon and SortableJS in Omeka itself.

- `codejar/` — [CodeJar](https://github.com/antonmedv/codejar) 4.2.0, MIT.
  `export` was rewritten to `window.CodeJar` so Omeka’s classic `headScript` can load it.
- `codejar-linenumbers/` — [codejar-linenumbers](https://github.com/julianpoemp/codejar-linenumbers) 1.0.1, MIT.
  The IIFE is invoked with the existing `CodeJar` function so `CodeJar.withLineNumbers` is attached instead of replacing the editor.

Highlighting is a small PHP tokenizer in `asset/js/code-snippets-editor.js`, not Prism.
