<?php

declare(strict_types=1);

namespace CodeSnippets\View;

use CodeSnippets\Module;
use ReflectionClass;

/**
 * Read files from this module's directory.
 *
 * Used to inline the editor when extra /modules/.../asset/... requests 404
 * (Omeka S Playground). Real installs still load the same files via assetUrl.
 */
class EditorAssets
{
    public static function root(): string
    {
        return dirname((new ReflectionClass(Module::class))->getFileName());
    }

    public static function contents(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || strpos($relative, '..') !== false) {
            return '';
        }
        $path = self::root() . '/' . ltrim($relative, '/');
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $data = file_get_contents($path);
        return $data === false ? '' : $data;
    }
}
