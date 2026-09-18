<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Controller;

use CodeSnippets\Form\SnippetForm;
use CodeSnippets\Service\ActionCsrf;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetService;
use CodeSnippetsTest\Support\ControllerHarness;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use Laminas\Session\Container as SessionContainer;
use Laminas\Validator\Csrf;
use Laminas\View\Model\ViewModel;
use Omeka\Mvc\Exception\PermissionDeniedException;
use PHPUnit\Framework\TestCase;

class SnippetControllerTest extends TestCase
{
    /** @var InMemorySnippetRepository */
    private $repository;

    /** @var SnippetService */
    private $service;

    protected function setUp(): void
    {
        SessionContainer::reset();
        $this->repository = new InMemorySnippetRepository();
        $this->service = new SnippetService($this->repository, new PhpValidator());
    }

    public function testIndexListsSnippets(): void
    {
        $this->service->create(['name' => 'One', 'code' => '$x = 1;']);
        $harness = $this->harness();

        $view = $harness->controller->indexAction();

        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertCount(1, $view->variables['snippets']);
        $this->assertFalse($view->variables['safeModeActive']);
        $this->assertSame('good-token', $view->variables['actionCsrf']);
    }

    public function testPermissionDeniedOnIndex(): void
    {
        $harness = $this->harness();
        $harness->allowed = false;
        $this->expectException(PermissionDeniedException::class);
        $harness->controller->indexAction();
    }

    public function testAddGetReturnsForm(): void
    {
        $harness = $this->harness();
        $view = $harness->controller->addAction();
        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertInstanceOf(SnippetForm::class, $view->variables['form']);
        $this->assertNull($view->variables['snippet']);
    }

    public function testAddPostCreatesSnippet(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = [
            'name' => 'Created',
            'description' => 'Desc',
            'code' => '$x = 1;',
            'priority' => '4',
            'active' => '0',
            'csrf' => $this->formCsrf(),
        ];

        $result = $harness->controller->addAction();

        $this->assertSame('redirect:admin/code-snippets/edit', $result);
        $this->assertSame(['id' => 1], $harness->redirect->params);
        $this->assertSame(['Snippet created.'], $harness->messenger->success);
        $created = $this->repository->find(1);
        $this->assertSame('Created', $created['name']);
        $this->assertSame(4, $created['priority']);
        $this->assertFalse($created['active']);
    }

    public function testAddPostSaveAndActivate(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = [
            'name' => 'Live',
            'code' => '$x = 1;',
            'csrf' => $this->formCsrf(),
            'submit_save_and_activate' => '1',
        ];

        $harness->controller->addAction();

        $this->assertTrue($this->repository->find(1)['active']);
    }

    public function testAddPostInvalidSyntax(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = [
            'name' => 'Broken',
            'code' => 'if (',
            'active' => '1',
            'csrf' => $this->formCsrf(),
        ];

        $view = $harness->controller->addAction();

        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertNotEmpty($harness->messenger->error);
        $this->assertNull($this->repository->find(1));
    }

    public function testAddPostInvalidForm(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = [
            'name' => '',
            'code' => '',
            'csrf' => $this->formCsrf(),
        ];

        $view = $harness->controller->addAction();

        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertNotEmpty($harness->messenger->formErrors);
    }

    public function testEditMissingIdRedirects(): void
    {
        $harness = $this->harness();
        $result = $harness->controller->editAction();
        $this->assertSame('redirect:admin/code-snippets', $result);
        $this->assertNotEmpty($harness->messenger->error);
    }

    public function testEditUnknownSnippetRedirects(): void
    {
        $harness = $this->harness();
        $harness->params->route = ['id' => '9'];
        $result = $harness->controller->editAction();
        $this->assertSame('redirect:admin/code-snippets', $result);
    }

    public function testEditGetPopulatesForm(): void
    {
        $this->service->create(['name' => 'Edit me', 'code' => '$x = 1;', 'priority' => 3]);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];

        $view = $harness->controller->editAction();

        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertSame('Edit me', $view->variables['snippet']['name']);
        $this->assertSame('good-token', $view->variables['actionCsrf']);
    }

    public function testEditPostUpdatesSnippet(): void
    {
        $this->service->create(['name' => 'Old', 'code' => '$x = 1;']);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];
        $harness->request->post = true;
        $harness->params->post = [
            'name' => 'New',
            'code' => '$y = 2;',
            'priority' => '8',
            'active' => '0',
            'csrf' => $this->formCsrf(),
            'submit_save_and_activate' => '1',
        ];

        $result = $harness->controller->editAction();

        $this->assertSame('redirect:admin/code-snippets/edit', $result);
        $updated = $this->repository->find(1);
        $this->assertSame('New', $updated['name']);
        $this->assertSame('$y = 2;', $updated['code']);
        $this->assertTrue($updated['active']);
        $this->assertSame(['Snippet updated.'], $harness->messenger->success);
    }

    public function testEditPostInvalidSyntax(): void
    {
        $this->service->create(['name' => 'Live', 'code' => '$x = 1;', 'active' => true]);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];
        $harness->request->post = true;
        $harness->params->post = [
            'name' => 'Live',
            'code' => 'if (',
            'active' => '1',
            'csrf' => $this->formCsrf(),
        ];

        $view = $harness->controller->editAction();

        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertNotEmpty($harness->messenger->error);
        $this->assertSame('$x = 1;', $this->repository->find(1)['code']);
    }

    public function testEditPostInvalidForm(): void
    {
        $this->service->create(['name' => 'Keep', 'code' => '$x = 1;']);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];
        $harness->request->post = true;
        $harness->params->post = [
            'name' => '',
            'code' => '',
            'csrf' => $this->formCsrf(),
        ];

        $view = $harness->controller->editAction();
        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertNotEmpty($harness->messenger->formErrors);
    }

    public function testActivateRequiresPost(): void
    {
        $harness = $this->harness();
        $result = $harness->controller->activateAction();
        $this->assertSame(405, $harness->response->statusCode);
        $this->assertSame($harness->response, $result);
        $this->assertSame([['Allow', 'POST']], $harness->response->headers->lines);
    }

    public function testActivateRejectsInvalidCsrf(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = ['csrf' => 'nope'];
        $result = $harness->controller->activateAction();
        $this->assertSame(400, $harness->response->statusCode);
        $this->assertSame('redirect:admin/code-snippets', $result);
        $this->assertSame(['Invalid CSRF token.'], $harness->messenger->error);
    }

    public function testActivateMissingId(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = ['csrf' => 'good-token'];
        $result = $harness->controller->activateAction();
        $this->assertSame('redirect:admin/code-snippets', $result);
    }

    public function testActivateUnknownSnippet(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->route = ['id' => '9'];
        $harness->params->post = ['csrf' => 'good-token'];
        $result = $harness->controller->activateAction();
        $this->assertSame('redirect:admin/code-snippets', $result);
    }

    public function testActivateInvalidSyntaxRedirectsToEdit(): void
    {
        $this->service->create(['name' => 'Broken', 'code' => 'if (', 'active' => false]);
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->route = ['id' => '1'];
        $harness->params->post = ['csrf' => 'good-token'];

        $result = $harness->controller->activateAction();

        $this->assertSame('redirect:admin/code-snippets/edit', $result);
        $this->assertFalse($this->repository->find(1)['active']);
        $this->assertNotEmpty($harness->messenger->error);
    }

    public function testActivateSuccess(): void
    {
        $this->service->create(['name' => 'Ok', 'code' => '$x = 1;', 'active' => false]);
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->route = ['id' => '1'];
        $harness->params->post = ['csrf' => 'good-token'];

        $result = $harness->controller->activateAction();

        $this->assertSame('redirect:admin/code-snippets', $result);
        $this->assertTrue($this->repository->find(1)['active']);
        $this->assertSame(['Snippet activated.'], $harness->messenger->success);
    }

    public function testDeactivateRequiresPost(): void
    {
        $harness = $this->harness();
        $harness->controller->deactivateAction();
        $this->assertSame(405, $harness->response->statusCode);
    }

    public function testDeactivateRejectsInvalidCsrf(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $result = $harness->controller->deactivateAction();
        $this->assertSame(400, $harness->response->statusCode);
        $this->assertSame('redirect:admin/code-snippets', $result);
    }

    public function testDeactivateMissingId(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->post = ['csrf' => 'good-token'];
        $this->assertSame(
            'redirect:admin/code-snippets',
            $harness->controller->deactivateAction()
        );
    }

    public function testDeactivateUnknownSnippet(): void
    {
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->route = ['id' => '9'];
        $harness->params->post = ['csrf' => 'good-token'];
        $this->assertSame(
            'redirect:admin/code-snippets',
            $harness->controller->deactivateAction()
        );
    }

    public function testDeactivateSuccess(): void
    {
        $this->service->create(['name' => 'On', 'code' => '$x = 1;', 'active' => true]);
        $harness = $this->harness();
        $harness->request->post = true;
        $harness->params->route = ['id' => '1'];
        $harness->params->post = ['csrf' => 'good-token'];

        $result = $harness->controller->deactivateAction();

        $this->assertSame('redirect:admin/code-snippets', $result);
        $this->assertFalse($this->repository->find(1)['active']);
        $this->assertSame(['Snippet deactivated.'], $harness->messenger->success);
    }

    public function testDeleteMissingId(): void
    {
        $harness = $this->harness();
        $this->assertSame('redirect:admin/code-snippets', $harness->controller->deleteAction());
    }

    public function testDeleteUnknownSnippet(): void
    {
        $harness = $this->harness();
        $harness->params->route = ['id' => '9'];
        $this->assertSame('redirect:admin/code-snippets', $harness->controller->deleteAction());
    }

    public function testDeleteGetShowsConfirm(): void
    {
        $this->service->create(['name' => 'Soon gone', 'code' => '$x = 1;']);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];
        $view = $harness->controller->deleteAction();
        $this->assertInstanceOf(ViewModel::class, $view);
        $this->assertSame('Soon gone', $view->variables['snippet']['name']);
        $this->assertSame('good-token', $view->variables['actionCsrf']);
    }

    public function testDeletePostRejectsInvalidCsrf(): void
    {
        $this->service->create(['name' => 'Keep', 'code' => '$x = 1;']);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];
        $harness->request->post = true;
        $harness->params->post = ['csrf' => 'bad'];
        $result = $harness->controller->deleteAction();
        $this->assertSame(400, $harness->response->statusCode);
        $this->assertSame('redirect:admin/code-snippets', $result);
        $this->assertNotNull($this->repository->find(1));
    }

    public function testDeletePostRemovesSnippet(): void
    {
        $this->service->create(['name' => 'Gone', 'code' => '$x = 1;']);
        $harness = $this->harness();
        $harness->params->route = ['id' => '1'];
        $harness->request->post = true;
        $harness->params->post = ['csrf' => 'good-token'];

        $result = $harness->controller->deleteAction();

        $this->assertSame('redirect:admin/code-snippets', $result);
        $this->assertNull($this->repository->find(1));
        $this->assertSame(['Snippet deleted.'], $harness->messenger->success);
    }

    public function testSafeModeQueryIsPreserved(): void
    {
        $harness = $this->harness();
        $harness->request->query = ['snippets-safe-mode' => '1'];
        $view = $harness->controller->indexAction();
        $this->assertTrue($view->variables['safeModeActive']);
        $this->assertSame(['snippets-safe-mode' => '1'], $view->variables['safeModeQuery']);
    }

    public function testIdentityWithoutRoleSkipsUrlSafeMode(): void
    {
        $harness = $this->harness();
        $harness->identityRole = 123;
        $harness->request->query = ['snippets-safe-mode' => '1'];
        $view = $harness->controller->indexAction();
        $this->assertFalse($view->variables['safeModeActive']);
    }

    public function testNullIdentitySkipsUrlSafeMode(): void
    {
        $harness = $this->harness();
        $harness->identityRole = null;
        $harness->request->query = ['snippets-safe-mode' => '1'];
        $view = $harness->controller->indexAction();
        $this->assertFalse($view->variables['safeModeActive']);
    }

    public function testInvalidRouteIdIsRejected(): void
    {
        $harness = $this->harness();
        $harness->params->route = ['id' => 'abc'];
        $this->assertSame('redirect:admin/code-snippets', $harness->controller->editAction());
    }

    private function harness(): ControllerHarness
    {
        $csrf = new ActionCsrf($this->actionCsrfValidator());
        return new ControllerHarness($this->service, new SafeMode(), $csrf);
    }

    private function actionCsrfValidator(): Csrf
    {
        $validator = $this->createMock(Csrf::class);
        $validator->method('getHash')->willReturn('good-token');
        $validator->method('isValid')->willReturnCallback(static function ($value) {
            return $value === 'good-token';
        });
        return $validator;
    }

    private function formCsrf(): string
    {
        $form = new SnippetForm('snippet');
        $form->init();
        return (string) $form->get('csrf')->getValue();
    }
}
