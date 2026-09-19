<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

/**
 * Runs active snippets once per HTTP request, in priority order.
 *
 * Event: Laminas\Mvc\MvcEvent::EVENT_ROUTE ('route') at priority -10.
 * That is after Omeka's default route listeners (priority 1): session is
 * already bootstrapped, identity is available, API-key auth and
 * prepareAdmin/preparePublicSite have run, and controller dispatch has not
 * started. Snippets can still attach listeners for dispatch and render.
 *
 * Each snippet is wrapped in its own try/catch (\Throwable). Recoverable
 * failures are logged and recorded; later snippets still run. This is not a
 * sandbox: exit, die, memory exhaustion, hard timeouts, and some engine-level
 * fatals (including redeclaring a function or class) cannot be recovered.
 *
 * Loading the snippets is guarded the same way. This listener runs on every
 * HTTP request, so an unreadable table (a pending schema upgrade, a database
 * failure) must skip execution rather than fail the request: otherwise the
 * whole site, public pages included, answers with an error.
 */
class SnippetExecutor
{
    public const EVENT_NAME = 'route';
    public const PRIORITY = -10;

    /** @var SnippetRepositoryInterface */
    private $repository;

    /** @var SafeMode */
    private $safeMode;

    /** @var SnippetEvaluator */
    private $evaluator;

    /** @var PhpValidator */
    private $validator;

    /** @var object|null */
    private $logger;

    /** @var object|null */
    private $authenticationService;

    /** @var bool */
    private $started = false;

    /** @var array<int, bool> */
    private $executedIds = [];

    /**
     * @param object|null $logger Omeka\Logger (Laminas\Log\Logger)
     * @param object|null $authenticationService Omeka\AuthenticationService
     */
    public function __construct(
        SnippetRepositoryInterface $repository,
        SafeMode $safeMode,
        SnippetEvaluator $evaluator,
        PhpValidator $validator,
        $logger = null,
        $authenticationService = null
    ) {
        $this->repository = $repository;
        $this->safeMode = $safeMode;
        $this->evaluator = $evaluator;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->authenticationService = $authenticationService;
    }

    /**
     * @param mixed $event Laminas\Mvc\MvcEvent
     */
    public function executeFromMvcEvent($event): void
    {
        $services = null;
        $request = null;
        if (is_object($event) && method_exists($event, 'getApplication')) {
            $application = $event->getApplication();
            if (is_object($application) && method_exists($application, 'getServiceManager')) {
                $services = $application->getServiceManager();
            }
            if (is_object($application) && method_exists($application, 'getRequest')) {
                $request = $application->getRequest();
            }
        }
        if ($request === null && is_object($event) && method_exists($event, 'getRequest')) {
            $request = $event->getRequest();
        }

        $this->run($services, $event, $request, $this->currentRole());
    }

    /**
     * @param mixed $services
     * @param mixed $event
     * @param mixed $request
     * @return int Number of snippets that were invoked (including those that threw)
     */
    public function run($services, $event, $request, ?string $role, ?string $sapi = null): int
    {
        if ($this->started) {
            return 0;
        }
        $this->started = true;

        if ($this->safeMode->shouldSkipExecution($request, $role, $sapi)) {
            return 0;
        }

        try {
            $snippets = $this->repository->findActiveOrdered(SnippetScope::fromMvcEvent($event));
        } catch (\Throwable $throwable) {
            $this->logLoadFailure($throwable);
            return 0;
        }

        $invoked = 0;
        foreach ($snippets as $snippet) {
            $id = (int) $snippet['id'];
            if (isset($this->executedIds[$id])) {
                continue;
            }
            $this->executedIds[$id] = true;
            $invoked++;
            $this->executeOne($snippet, $services, $event);
        }

        return $invoked;
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * @return array<int, bool>
     */
    public function getExecutedIds(): array
    {
        return $this->executedIds;
    }

    /**
     * @param array<string, mixed> $snippet
     * @param mixed $services
     * @param mixed $event
     */
    private function executeOne(array $snippet, $services, $event): void
    {
        $code = $this->validator->normalize((string) $snippet['code']);
        try {
            $this->evaluator->evaluate($code, $services, $event);
        } catch (\Throwable $throwable) {
            $this->handleFailure($snippet, $throwable);
        }
    }

    /**
     * @param array<string, mixed> $snippet
     */
    private function handleFailure(array $snippet, \Throwable $throwable): void
    {
        $id = (int) $snippet['id'];
        $name = (string) $snippet['name'];
        $type = get_class($throwable);
        $message = $throwable->getMessage();
        $line = $throwable->getLine();

        $this->logError($id, $name, $type, $message, $line);

        try {
            $this->repository->recordError($id, $type, $message, $line > 0 ? $line : null);
        } catch (\Throwable $ignored) {
            $this->logError($id, $name, get_class($ignored), $ignored->getMessage(), $ignored->getLine());
        }
    }

    private function logLoadFailure(\Throwable $throwable): void
    {
        if ($this->logger === null || !method_exists($this->logger, 'err')) {
            return;
        }
        $this->logger->err(sprintf(
            'CodeSnippets: snippets could not be loaded, none were run: %s: %s',
            get_class($throwable),
            $throwable->getMessage()
        ));
    }

    private function logError(int $id, string $name, string $type, string $message, int $line): void
    {
        if ($this->logger === null || !method_exists($this->logger, 'err')) {
            return;
        }
        $this->logger->err(sprintf(
            'CodeSnippets: snippet #%d "%s" failed with %s: %s (line %d)',
            $id,
            $name,
            $type,
            $message,
            $line
        ));
    }

    private function currentRole(): ?string
    {
        if ($this->authenticationService === null
            || !method_exists($this->authenticationService, 'getIdentity')
        ) {
            return null;
        }
        try {
            $identity = $this->authenticationService->getIdentity();
        } catch (\Throwable $ignored) {
            return null;
        }
        if (is_object($identity) && method_exists($identity, 'getRole')) {
            $role = $identity->getRole();
            return is_string($role) ? $role : null;
        }
        return null;
    }
}
