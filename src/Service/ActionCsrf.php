<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

use Laminas\Validator\Csrf;

/**
 * CSRF tokens for POST activate / deactivate / delete (not the snippet form).
 */
class ActionCsrf
{
    public const NAME = 'code_snippets_action';

    /** @var Csrf */
    private $validator;

    public function __construct(?Csrf $validator = null)
    {
        $this->validator = $validator ?: new Csrf([
            'name' => self::NAME,
            'timeout' => 43200,
        ]);
    }

    public function getToken(): string
    {
        return $this->validator->getHash();
    }

    /**
     * @param mixed $token
     */
    public function isValid($token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        return $this->validator->isValid($token);
    }
}
