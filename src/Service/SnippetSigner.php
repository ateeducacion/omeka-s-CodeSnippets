<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

use CodeSnippets\Exception\SnippetIntegrityException;

/** Authenticates stored executable state; never an execution sandbox. */
class SnippetSigner
{
    public const DISABLED = 'disabled';
    public const ENABLED = 'enabled';
    public const MISCONFIGURED = 'misconfigured';
    public const PREFIX = 'hmac-sha256:v1:';
    public const DOMAIN = "omeka-s-code-snippets\0signature-v1\0";

    /** @var string|null */
    private $key;

    /** @var string */
    private $state;

    /** @param mixed $key External configuration only, never settings or request data. */
    public function __construct($key = null)
    {
        if ($key === null || $key === '') {
            $this->state = self::DISABLED;
        } elseif (is_string($key) && strlen($key) >= 32) {
            $this->state = self::ENABLED;
            $this->key = $key;
        } else {
            $this->state = self::MISCONFIGURED;
        }
    }

    /** @param mixed $config Merged Omeka configuration, including local.config.php. */
    public static function fromConfig($config): self
    {
        if (!is_array($config)) {
            return new self(false);
        }
        if (!array_key_exists('code_snippets', $config)) {
            return new self();
        }
        $module = $config['code_snippets'];
        return new self(is_array($module) ? ($module['signing_key'] ?? null) : false);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function __debugInfo(): array
    {
        return ['state' => $this->state];
    }

    public function __serialize(): array
    {
        throw new SnippetIntegrityException('The snippet signing service cannot be serialized.');
    }

    public function sign(array $snippet): string
    {
        if ($this->state !== self::ENABLED) {
            throw new SnippetIntegrityException('Snippet signing is unavailable. Check the external configuration.');
        }
        try {
            $payload = [
                'id' => (int) $snippet['id'],
                'name' => (string) $snippet['name'],
                'description' => $snippet['description'] === null ? null : (string) $snippet['description'],
                'code' => (string) $snippet['code'],
                'priority' => (int) $snippet['priority'],
                'active' => (bool) $snippet['active'],
                'run_scope' => (string) $snippet['run_scope'],
            ];
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return self::PREFIX . hash_hmac('sha256', self::DOMAIN . $json, $this->key);
        } catch (\Throwable $ignored) {
            // Do not chain exceptions or expose payloads or cryptographic material.
            throw new SnippetIntegrityException('Snippet integrity signing failed.');
        }
    }

    public function verify(array $snippet): bool
    {
        if ($this->state === self::DISABLED) {
            return true;
        }
        if ($this->state !== self::ENABLED) {
            return false;
        }
        $actual = $snippet['signature'] ?? null;
        if (!is_string($actual) || !preg_match('/\Ahmac-sha256:v1:[a-f0-9]{64}\z/', $actual)) {
            return false;
        }
        try {
            return hash_equals($this->sign($snippet), $actual);
        } catch (\Throwable $ignored) {
            return false;
        }
    }
}
