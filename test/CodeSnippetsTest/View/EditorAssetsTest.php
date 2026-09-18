<?php

declare(strict_types=1);

namespace CodeSnippetsTest\View;

use CodeSnippets\View\EditorAssets;
use PHPUnit\Framework\TestCase;

class EditorAssetsTest extends TestCase
{
    public function testReadsEditorFilesFromModuleRoot(): void
    {
        $css = EditorAssets::contents('asset/css/code-snippets.css');
        $js = EditorAssets::contents('asset/js/code-snippets-editor.js');
        $jar = EditorAssets::contents('asset/vendor/codejar/codejar.js');
        $lines = EditorAssets::contents('asset/vendor/codejar-linenumbers/codejar-linenumbers.js');

        $this->assertStringContainsString('.cs-keyword', $css);
        $this->assertStringContainsString('highlightPhp', $js);
        $this->assertStringContainsString('DOMContentLoaded', $js);
        $this->assertStringContainsString('function CodeJar', $jar);
        $this->assertStringContainsString('withLineNumbers', $lines);
        $this->assertSame('', EditorAssets::contents('asset/../Module.php'));
    }
}
