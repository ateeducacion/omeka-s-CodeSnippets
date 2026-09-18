<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

/**
 * Syntax-only PHP validator.
 *
 * Uses token_get_all(..., TOKEN_PARSE) so a ParseError is raised without
 * executing the snippet. Never shells out to `php -l`.
 */
class PhpValidator
{
    public function validate(string $code): ValidationResult
    {
        $normalized = $this->normalize($code);
        if (trim($normalized) === '') {
            return ValidationResult::invalid('Snippet code is empty.');
        }

        $wrapped = "<?php\n" . $normalized;
        try {
            token_get_all($wrapped, TOKEN_PARSE);
        } catch (\ParseError $error) {
            $line = $error->getLine() > 0 ? $error->getLine() - 1 : null;
            if ($line !== null && $line < 1) {
                $line = 1;
            }
            return ValidationResult::invalid($error->getMessage(), $line);
        }

        return ValidationResult::valid();
    }

    /**
     * Strip an optional opening PHP tag and a trailing close tag.
     *
     * The rest of the source is stored and executed unchanged.
     */
    public function normalize(string $code): string
    {
        if (strncmp($code, "\xEF\xBB\xBF", 3) === 0) {
            $code = substr($code, 3);
        }

        $code = ltrim($code);

        if (strncmp($code, '<?php', 5) === 0) {
            $code = substr($code, 5);
            $code = $this->stripLeadingNewline($code);
        } elseif (preg_match('/^<\?(?!php|=)/', $code)) {
            $code = substr($code, 2);
            $code = $this->stripLeadingNewline($code);
        }

        $trimmed = rtrim($code);
        if (substr($trimmed, -2) === '?>') {
            $code = rtrim(substr($trimmed, 0, -2));
        }

        return $code;
    }

    private function stripLeadingNewline(string $code): string
    {
        if (isset($code[0]) && $code[0] === "\r") {
            $code = substr($code, 1);
        }
        if (isset($code[0]) && $code[0] === "\n") {
            $code = substr($code, 1);
        }
        return $code;
    }
}
