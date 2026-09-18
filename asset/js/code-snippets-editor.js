(function () {
    var textarea = document.getElementById('code-snippets-code');
    if (!textarea || typeof CodeJar !== 'function') {
        return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'code-snippets-editor language-php';
    wrap.setAttribute('spellcheck', 'false');
    wrap.setAttribute('aria-label', textarea.getAttribute('aria-label') || 'PHP code');
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.textContent = textarea.value;
    textarea.setAttribute('hidden', 'hidden');
    textarea.tabIndex = -1;

    var highlight = function (editor) {
        if (!window.Prism || !Prism.languages || !Prism.languages.php) {
            return;
        }
        editor.innerHTML = Prism.highlight(
            editor.textContent || '',
            Prism.languages.php,
            'php'
        );
    };

    var jar = CodeJar(wrap, highlight, { tab: '    ' });
    jar.onUpdate(function (code) {
        textarea.value = code;
    });
})();
