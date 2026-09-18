<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

/**
 * Where an active snippet is allowed to run.
 *
 * Values and labels follow the WordPress Code Snippets plugin PHP scopes:
 * global, admin, and front-end. "Only run once" is not implemented.
 *
 * Mapping to Omeka: administration area is a route with `__ADMIN__`
 * (set by Omeka's prepareAdmin on EVENT_ROUTE). Every other HTTP request
 * (public site, login, API) is treated as the front-end, matching
 * WordPress `is_admin()`.
 */
class SnippetScope
{
    public const GLOBAL = 'global';
    public const ADMIN = 'admin';
    public const FRONT_END = 'front-end';
    public const DEFAULT = self::GLOBAL;

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::GLOBAL, self::ADMIN, self::FRONT_END];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::GLOBAL => 'Run snippet everywhere', // @translate
            self::ADMIN => 'Only run in administration area', // @translate
            self::FRONT_END => 'Only run on site front-end', // @translate
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function shortLabels(): array
    {
        return [
            self::GLOBAL => 'Everywhere', // @translate
            self::ADMIN => 'Administration area', // @translate
            self::FRONT_END => 'Site front-end', // @translate
        ];
    }

    /**
     * @param mixed $value
     */
    public static function normalize($value): string
    {
        if (!is_string($value) || !in_array($value, self::all(), true)) {
            return self::DEFAULT;
        }
        return $value;
    }

    /**
     * @param mixed $event Laminas\Mvc\MvcEvent
     */
    public static function fromMvcEvent($event): string
    {
        if (is_object($event) && method_exists($event, 'getRouteMatch')) {
            $match = $event->getRouteMatch();
            if (is_object($match) && method_exists($match, 'getParam')
                && $match->getParam('__ADMIN__')
            ) {
                return self::ADMIN;
            }
        }
        return self::FRONT_END;
    }

    public static function matches(string $snippetScope, string $requestScope): bool
    {
        $snippetScope = self::normalize($snippetScope);
        $requestScope = self::normalize($requestScope);
        return $snippetScope === self::GLOBAL || $snippetScope === $requestScope;
    }
}
