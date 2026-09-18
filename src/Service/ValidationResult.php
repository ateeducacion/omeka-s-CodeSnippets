<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

class ValidationResult
{
    /** @var bool */
    private $valid;

    /** @var string|null */
    private $message;

    /** @var int|null */
    private $line;

    public function __construct(bool $valid, ?string $message = null, ?int $line = null)
    {
        $this->valid = $valid;
        $this->message = $message;
        $this->line = $line;
    }

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(string $message, ?int $line = null): self
    {
        return new self(false, $message, $line);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }
}
