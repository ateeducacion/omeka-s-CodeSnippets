# Vendored editor assets

Omeka S ships CKEditor for HTML, not a PHP code editor. These files are copied
into the module so the snippet form can highlight PHP without a CDN.

- `codejar/` — [CodeJar](https://github.com/antonmedv/codejar) 4.2.0, MIT.
  `export` was rewritten to `window.CodeJar` so Omeka's classic `headScript` can load it.
- `prism/` — [PrismJS](https://github.com/PrismJS/prism) 1.29.0, MIT.
  Core plus `markup-templating` and `php`, with the default light theme.

The snippet `<textarea>` remains the form field. If JavaScript fails, the
textarea stays usable.
