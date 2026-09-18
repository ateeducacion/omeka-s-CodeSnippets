<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

/**
 * Centralized execution gates.
 *
 * Decision order:
 * 1. PHP CLI — stored snippets never run under CLI by default.
 * 2. Emergency global constant / environment variable.
 * 3. Per-request URL parameter, only for an authenticated global_admin.
 *
 * Callers must consult this service before loading or executing snippets.
 */
class SafeMode
{
    public const QUERY_PARAM = 'snippets-safe-mode';
    public const CONSTANT_NAME = 'OMEKA_CODE_SNIPPETS_SAFE_MODE';
    public const ENV_NAME = 'OMEKA_CODE_SNIPPETS_SAFE_MODE';
    public const GLOBAL_ADMIN_ROLE = 'global_admin';

    public function isCli(?string $sapi = null): bool
    {
        if ($sapi === null) {
            $sapi = PHP_SAPI;
        }
        return $sapi === 'cli';
    }

    /**
     * Process-wide kill switch. Independent of auth, session, routing, and DB.
     */
    public function isEmergencySafeMode(): bool
    {
        if (defined(self::CONSTANT_NAME) && constant(self::CONSTANT_NAME)) {
            return true;
        }

        $env = getenv(self::ENV_NAME);
        if ($env === false || $env === '') {
            return false;
        }

        $normalized = strtolower((string) $env);
        return $normalized === '1' || $normalized === 'true' || $normalized === 'yes';
    }

    /**
     * Request-scoped safe mode. Never persist this flag.
     *
     * @param mixed $request Laminas request, query array, or null
     */
    public function isUrlSafeMode($request, ?string $role): bool
    {
        if ($role !== self::GLOBAL_ADMIN_ROLE) {
            return false;
        }
        return $this->isQueryFlagPresent($request);
    }

    /**
     * @param mixed $request
     */
    public function isQueryFlagPresent($request): bool
    {
        $value = $this->readQueryValue($request);
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * @param mixed $request
     */
    public function shouldSkipExecution($request, ?string $role, ?string $sapi = null): bool
    {
        if ($this->isCli($sapi)) {
            return true;
        }
        if ($this->isEmergencySafeMode()) {
            return true;
        }
        if ($this->isUrlSafeMode($request, $role)) {
            return true;
        }
        return false;
    }

    /**
     * Query parameters to keep URL safe mode while navigating this module.
     *
     * @param mixed $request
     * @return array<string, string>
     */
    public function preservedQuery($request, ?string $role): array
    {
        if ($this->isUrlSafeMode($request, $role)) {
            return [self::QUERY_PARAM => '1'];
        }
        return [];
    }

    /**
     * @param mixed $request
     * @return mixed
     */
    private function readQueryValue($request)
    {
        if (is_array($request)) {
            return $request[self::QUERY_PARAM] ?? null;
        }

        if (is_object($request) && method_exists($request, 'getQuery')) {
            $query = $request->getQuery(self::QUERY_PARAM, null);
            if (is_object($query) && method_exists($query, 'get')) {
                return $query->get(self::QUERY_PARAM);
            }
            return $query;
        }

        if (isset($_GET[self::QUERY_PARAM])) {
            return $_GET[self::QUERY_PARAM];
        }

        return null;
    }
}
