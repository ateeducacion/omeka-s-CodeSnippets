<?php

declare(strict_types=1);

namespace CodeSnippets\View;

use CodeSnippets\Module;
use ReflectionClass;

/**
 * Read module asset files from disk.
 *
 * Used to inline the snippet editor so highlighting does not depend on
 * extra /modules/.../asset/... requests (those 404 in Omeka S Playground).
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
