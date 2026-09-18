# Vendored editor assets

Omeka S ships CKEditor for HTML, not a PHP highlighter. CodeJar is a small
contenteditable editor; it does **not** color tokens by itself. Its API takes
a highlight callback. This module uses a tiny PHP tokenizer in
`asset/js/code-snippets-editor.js` instead of Prism or highlight.js.

The add/edit form inlines CodeJar, the tokenizer, and the CSS so the editor
still works when extra `/modules/.../asset/...` requests 404 (Omeka S Playground).

- `codejar/` — [CodeJar](https://github.com/antonmedv/codejar) 4.2.0, MIT.
  `export` was rewritten to `window.CodeJar` so it can run as a classic script.
