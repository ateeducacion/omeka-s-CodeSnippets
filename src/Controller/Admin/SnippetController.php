<?php

declare(strict_types=1);

namespace CodeSnippets\Controller\Admin;

use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Exception\SnippetIntegrityException;
use CodeSnippets\Exception\SnippetNotFoundException;
use CodeSnippets\Form\SnippetForm;
use CodeSnippets\Service\ActionCsrf;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetScope;
use CodeSnippets\Service\SnippetService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Omeka\Stdlib\Message;

class SnippetController extends AbstractActionController
{
    public const RESOURCE = self::class;

    /** @var SnippetService */
    private $snippetService;

    /** @var SafeMode */
    private $safeMode;

    /** @var ActionCsrf */
    private $actionCsrf;

    public function __construct(
        SnippetService $snippetService,
        SafeMode $safeMode,
        ActionCsrf $actionCsrf
    ) {
        $this->snippetService = $snippetService;
        $this->safeMode = $safeMode;
        $this->actionCsrf = $actionCsrf;
    }

    public function indexAction()
    {
        $this->assertAllowed('index');
        $snippets = $this->snippetService->findAll();
        $integrityStatuses = [];
        foreach ($snippets as $snippet) {
            $integrityStatuses[$snippet['id']] = $this->snippetService->integrityStatus($snippet);
        }
        $view = new ViewModel([
            'snippets' => $snippets,
            'integrityStatuses' => $integrityStatuses,
            'safeModeActive' => $this->isUrlSafeMode(),
            'emergencySafeMode' => $this->safeMode->isEmergencySafeMode(),
            'safeModeQuery' => $this->safeModeQuery(),
            'actionCsrf' => $this->actionCsrf->getToken(),
        ]);
        return $view;
    }

    public function addAction()
    {
        $this->assertAllowed('add');
        $form = $this->createForm();

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                try {
                    $data = $this->formData($form);
                    if ($this->params()->fromPost('submit_save_and_activate')) {
                        $data['active'] = true;
                    }
                    $snippet = $this->snippetService->create($data);
                    $this->messenger()->addSuccess('Snippet created.'); // @translate
                    return $this->redirectToEdit((int) $snippet['id']);
                } catch (InvalidSyntaxException $exception) {
                    $this->addSyntaxError($exception);
                } catch (SnippetIntegrityException $exception) {
                    $this->addIntegrityError();
                } catch (\InvalidArgumentException $exception) {
                    $this->messenger()->addError($exception->getMessage());
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'form' => $form,
            'snippet' => null,
            'safeModeActive' => $this->isUrlSafeMode(),
            'safeModeQuery' => $this->safeModeQuery(),
        ]);
    }

    public function editAction()
    {
        $this->assertAllowed('edit');
        $id = $this->requireId();
        if ($id === null) {
            return $this->notFound();
        }
        $snippet = $this->loadSnippet($id);
        if ($snippet === null) {
            return $this->notFound();
        }

        $form = $this->createForm();
        $form->setData([
            'name' => $snippet['name'],
            'description' => $snippet['description'],
            'code' => $snippet['code'],
            'priority' => $snippet['priority'],
            'run_scope' => $snippet['run_scope'] ?? SnippetScope::DEFAULT,
            'active' => $snippet['active'] ? '1' : '0',
        ]);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                try {
                    $data = $this->formData($form);
                    if ($this->params()->fromPost('submit_save_and_activate')) {
                        $data['active'] = true;
                    }
                    $snippet = $this->snippetService->update($id, $data);
                    $this->messenger()->addSuccess('Snippet updated.'); // @translate
                    return $this->redirectToEdit((int) $snippet['id']);
                } catch (InvalidSyntaxException $exception) {
                    $this->addSyntaxError($exception);
                } catch (SnippetIntegrityException $exception) {
                    $this->addIntegrityError();
                } catch (\InvalidArgumentException $exception) {
                    $this->messenger()->addError($exception->getMessage());
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'form' => $form,
            'snippet' => $snippet,
            'integrityStatus' => $this->snippetService->integrityStatus($snippet),
            'safeModeActive' => $this->isUrlSafeMode(),
            'safeModeQuery' => $this->safeModeQuery(),
            'actionCsrf' => $this->actionCsrf->getToken(),
        ]);
    }

    public function activateAction()
    {
        $this->assertAllowed('activate');
        if (!$this->getRequest()->isPost()) {
            return $this->methodNotAllowed();
        }
        if (!$this->actionCsrf->isValid($this->params()->fromPost('csrf'))) {
            return $this->invalidCsrf();
        }

        $id = $this->requireId();
        if ($id === null) {
            return $this->notFound();
        }
        try {
            $this->snippetService->activate($id);
            $this->messenger()->addSuccess('Snippet activated.'); // @translate
        } catch (SnippetNotFoundException $exception) {
            return $this->notFound();
        } catch (InvalidSyntaxException $exception) {
            $this->addSyntaxError($exception);
            return $this->redirectToEdit($id);
        } catch (SnippetIntegrityException $exception) {
            $this->addIntegrityError();
            return $this->redirectToEdit($id);
        }

        return $this->redirectToIndex();
    }

    public function deactivateAction()
    {
        $this->assertAllowed('deactivate');
        if (!$this->getRequest()->isPost()) {
            return $this->methodNotAllowed();
        }
        if (!$this->actionCsrf->isValid($this->params()->fromPost('csrf'))) {
            return $this->invalidCsrf();
        }

        $id = $this->requireId();
        if ($id === null) {
            return $this->notFound();
        }
        try {
            $this->snippetService->deactivate($id);
            $this->messenger()->addSuccess('Snippet deactivated.'); // @translate
        } catch (SnippetNotFoundException $exception) {
            return $this->notFound();
        }

        return $this->redirectToIndex();
    }

    public function deleteAction()
    {
        $this->assertAllowed('delete');
        $id = $this->requireId();
        if ($id === null) {
            return $this->notFound();
        }
        $snippet = $this->loadSnippet($id);
        if ($snippet === null) {
            return $this->notFound();
        }

        if ($this->getRequest()->isPost()) {
            if (!$this->actionCsrf->isValid($this->params()->fromPost('csrf'))) {
                return $this->invalidCsrf();
            }
            $this->snippetService->delete($id);
            $this->messenger()->addSuccess('Snippet deleted.'); // @translate
            return $this->redirectToIndex();
        }

        $view = new ViewModel([
            'snippet' => $snippet,
            'safeModeActive' => $this->isUrlSafeMode(),
            'safeModeQuery' => $this->safeModeQuery(),
            'actionCsrf' => $this->actionCsrf->getToken(),
        ]);

        // Browse rows load the confirmation into the admin sidebar; the plain
        // URL still renders the full page for requests without JavaScript.
        if ($this->getRequest()->getQuery('sidebar')) {
            $view->setTemplate('code-snippets/admin/snippet/delete-confirm');
            $view->setTerminal(true);
        }

        return $view;
    }

    private function createForm(): SnippetForm
    {
        $form = new SnippetForm('snippet');
        $form->init();
        $form->setAttribute('action', $this->url()->fromRoute(null, [], [
            'query' => $this->safeModeQuery(),
        ], true));
        return $form;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(SnippetForm $form): array
    {
        $data = $form->getData();
        unset($data['csrf'], $data['snippetform_csrf'], $data['submit']);
        return [
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'code' => $data['code'] ?? '',
            'priority' => $data['priority'] ?? 10,
            'run_scope' => $data['run_scope'] ?? SnippetScope::DEFAULT,
            'active' => !empty($data['active']),
        ];
    }

    private function assertAllowed(string $privilege): void
    {
        if (!$this->userIsAllowed(self::RESOURCE, $privilege)) {
            throw new PermissionDeniedException(
                $this->translate('You are not allowed to manage code snippets.')
            );
        }
    }

    private function requireId(): ?int
    {
        $raw = $this->params()->fromRoute('id');
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        return (int) $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSnippet(int $id): ?array
    {
        try {
            return $this->snippetService->find($id);
        } catch (SnippetNotFoundException $exception) {
            return null;
        }
    }

    private function notFound()
    {
        $this->messenger()->addError('The requested snippet was not found.'); // @translate
        return $this->redirectToIndex();
    }

    private function methodNotAllowed()
    {
        $response = $this->getResponse();
        $response->setStatusCode(405);
        $response->getHeaders()->addHeaderLine('Allow', 'POST');
        $response->setContent($this->translate('Method not allowed.'));
        return $response;
    }

    private function invalidCsrf()
    {
        $this->messenger()->addError('Invalid CSRF token.'); // @translate
        $response = $this->getResponse();
        $response->setStatusCode(400);
        return $this->redirectToIndex();
    }

    private function addSyntaxError(InvalidSyntaxException $exception): void
    {
        $this->messenger()->addError(new Message(
            'The snippet contains invalid PHP syntax: %s', // @translate
            $exception->getMessage()
        ));
    }

    private function addIntegrityError(): void
    {
        $this->messenger()->addError('Snippet signing failed. Check the external signing configuration.'); // @translate
    }

    private function redirectToIndex()
    {
        return $this->redirect()->toRoute('admin/code-snippets', [], [
            'query' => $this->safeModeQuery(),
        ]);
    }

    private function redirectToEdit(int $id)
    {
        return $this->redirect()->toRoute('admin/code-snippets/edit', ['id' => $id], [
            'query' => $this->safeModeQuery(),
        ]);
    }

    private function isUrlSafeMode(): bool
    {
        return $this->safeMode->isUrlSafeMode($this->getRequest(), $this->currentRole());
    }

    /**
     * @return array<string, string>
     */
    private function safeModeQuery(): array
    {
        return $this->safeMode->preservedQuery($this->getRequest(), $this->currentRole());
    }

    private function currentRole(): ?string
    {
        $identity = $this->identity();
        if (is_object($identity) && method_exists($identity, 'getRole')) {
            $role = $identity->getRole();
            return is_string($role) ? $role : null;
        }
        return null;
    }
}
