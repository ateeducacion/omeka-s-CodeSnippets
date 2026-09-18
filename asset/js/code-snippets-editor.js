(function () {
    function boot() {
        initEditor();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    function initEditor() {
    if (window.__codeSnippetsEditorInit) {
        return;
    }
    var textarea = document.getElementById('code-snippets-code');
    if (!textarea || typeof CodeJar !== 'function') {
        return;
    }
    window.__codeSnippetsEditorInit = true;


    var KEYWORDS = {
        abstract: 1, and: 1, array: 1, as: 1, break: 1, callable: 1, case: 1,
        catch: 1, class: 1, clone: 1, const: 1, continue: 1, declare: 1,
        default: 1, die: 1, do: 1, echo: 1, else: 1, elseif: 1, empty: 1,
        enddeclare: 1, endfor: 1, endforeach: 1, endif: 1, endswitch: 1,
        endwhile: 1, eval: 1, exit: 1, extends: 1, final: 1, finally: 1,
        fn: 1, for: 1, foreach: 1, function: 1, global: 1, goto: 1, if: 1,
        implements: 1, include: 1, include_once: 1, instanceof: 1,
        insteadof: 1, interface: 1, isset: 1, list: 1, match: 1,
        namespace: 1, new: 1, or: 1, print: 1, private: 1, protected: 1,
        public: 1, readonly: 1, require: 1, require_once: 1, return: 1,
        static: 1, switch: 1, throw: 1, trait: 1, try: 1, unset: 1, use: 1,
        var: 1, while: 1, xor: 1, yield: 1, true: 1, false: 1, null: 1,
        self: 1, parent: 1
    };

    function escapeHtml(value) {
        return value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function wrap(kind, value) {
        return '<span class="cs-' + kind + '">' + escapeHtml(value) + '</span>';
    }

    function isIdentStart(ch) {
        return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') || ch === '_';
    }

    function isIdent(ch) {
        return isIdentStart(ch) || (ch >= '0' && ch <= '9');
    }

    function highlightPhp(source) {
        var out = '';
        var i = 0;
        var n = source.length;

        while (i < n) {
            var ch = source.charAt(i);
            var next = i + 1 < n ? source.charAt(i + 1) : '';

            if (ch === '/' && next === '/') {
                var lineEnd = source.indexOf('\n', i);
                if (lineEnd === -1) {
                    lineEnd = n;
                }
                out += wrap('comment', source.slice(i, lineEnd));
                i = lineEnd;
                continue;
            }

            if (ch === '/' && next === '*') {
                var blockEnd = source.indexOf('*/', i + 2);
                blockEnd = blockEnd === -1 ? n : blockEnd + 2;
                out += wrap('comment', source.slice(i, blockEnd));
                i = blockEnd;
                continue;
            }

            if (ch === '\'' || ch === '"') {
                var quote = ch;
                var j = i + 1;
                while (j < n) {
                    var qch = source.charAt(j);
                    if (qch === '\\') {
                        j += 2;
                        continue;
                    }
                    if (qch === quote) {
                        j += 1;
                        break;
                    }
                    j += 1;
                }
                out += wrap('string', source.slice(i, j));
                i = j;
                continue;
            }

            if (ch === '$' && isIdentStart(next)) {
                var k = i + 1;
                while (k < n && isIdent(source.charAt(k))) {
                    k += 1;
                }
                out += wrap('variable', source.slice(i, k));
                i = k;
                continue;
            }

            if ((ch >= '0' && ch <= '9') && (i === 0 || !isIdent(source.charAt(i - 1)))) {
                var d = i;
                while (d < n && source.charAt(d) >= '0' && source.charAt(d) <= '9') {
                    d += 1;
                }
                out += wrap('number', source.slice(i, d));
                i = d;
                continue;
            }

            if (isIdentStart(ch)) {
                var w = i;
                while (w < n && isIdent(source.charAt(w))) {
                    w += 1;
                }
                var word = source.slice(i, w);
                out += KEYWORDS[word] ? wrap('keyword', word) : escapeHtml(word);
                i = w;
                continue;
            }

            out += escapeHtml(ch);
            i += 1;
        }

        return out;
    }

    var wrapEl = document.createElement('div');
    wrapEl.className = 'code-snippets-editor';
    wrapEl.setAttribute('spellcheck', 'false');
    wrapEl.setAttribute('aria-label', textarea.getAttribute('aria-label') || 'PHP code');
    textarea.parentNode.insertBefore(wrapEl, textarea);
    wrapEl.textContent = textarea.value;
    textarea.setAttribute('hidden', 'hidden');
    textarea.tabIndex = -1;

    var highlight = function (editor) {
        editor.innerHTML = highlightPhp(editor.textContent || '');
    };
    if (typeof CodeJar.withLineNumbers === 'function') {
        highlight = CodeJar.withLineNumbers(highlight);
    }

    var jar = CodeJar(wrapEl, highlight, { tab: '    ' });

    jar.onUpdate(function (code) {
        textarea.value = code;
    });
    }
})();
