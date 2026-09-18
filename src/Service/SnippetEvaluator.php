<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

/**
 * Isolated eval() entry point.
 *
 * Why eval() exists: snippets are administrator-authored PHP stored in the
 * dedicated `code_snippet` table. Dynamic execution is the product. eval()
 * is reached only after code has been submitted, syntax-validated, stored,
 * loaded as an active row, and selected by SnippetExecutor. Request input
 * is never passed here.
 *
 * Isolation: a private static method so `$this` is not in scope. The only
 * local variables inherited by eval() are `$services` and `$event`. The
 * source lives on a private static property, not a local variable.
 *
 * This is not a sandbox. A global administrator who can save PHP runs it
 * with the privileges of the Omeka S PHP process.
 */
class SnippetEvaluator
{
    /** @var string */
    private static $code = '';

    /**
     * @param mixed $services Omeka service locator
     * @param mixed $event Current MVC event
     */
    public function evaluate(string $code, $services, $event): void
    {
        self::$code = $code;
        try {
            self::doEval($services, $event);
        } finally {
            self::$code = '';
        }
    }

    /**
     * @param mixed $services
     * @param mixed $event
     */
    private static function doEval($services, $event): void
    {
        eval(self::$code);
    }
}
