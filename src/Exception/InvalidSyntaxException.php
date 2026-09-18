<?php

declare(strict_types=1);

namespace CodeSnippets\Exception;

/**
 * Thrown when snippet PHP fails syntax validation and must not become active.
 */
class InvalidSyntaxException extends \RuntimeException
{
    /** @var int|null */
    private $syntaxLine;

    public function __construct(string $message, ?int $syntaxLine = null)
    {
        parent::__construct($message);
        $this->syntaxLine = $syntaxLine;
    }

    public function getSyntaxLine(): ?int
    {
        return $this->syntaxLine;
    }
}
