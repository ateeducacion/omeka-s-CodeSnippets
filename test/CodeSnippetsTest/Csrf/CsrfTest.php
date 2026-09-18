<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Csrf;

use CodeSnippets\Form\SnippetForm;
use CodeSnippets\Service\ActionCsrf;
use Laminas\Form\Element\Csrf as CsrfElement;
use Laminas\Validator\Csrf;
use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    public function testSnippetFormIncludesCsrfElement(): void
    {
        $form = new SnippetForm('snippet');
        $form->init();
        $this->assertTrue($form->has('csrf'));
        $this->assertInstanceOf(CsrfElement::class, $form->get('csrf'));
    }

    public function testEmptyActionTokenIsRejected(): void
    {
        $validator = $this->createMock(Csrf::class);
        $validator->method('isValid')->willReturn(false);
        $csrf = new ActionCsrf($validator);
        $this->assertFalse($csrf->isValid(null));
        $this->assertFalse($csrf->isValid(''));
        $this->assertFalse($csrf->isValid('invalid-token'));
    }

    public function testValidActionTokenIsAccepted(): void
    {
        $validator = $this->createMock(Csrf::class);
        $validator->method('getHash')->willReturn('good-token');
        $validator->method('isValid')->willReturnCallback(static function ($value) {
            return $value === 'good-token';
        });
        $csrf = new ActionCsrf($validator);
        $this->assertSame('good-token', $csrf->getToken());
        $this->assertTrue($csrf->isValid('good-token'));
        $this->assertFalse($csrf->isValid('other'));
    }

    public function testStateChangingRoutesArePostOnlyInControllerSource(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/Admin/SnippetController.php');
        $this->assertNotFalse($source);
        foreach (['activateAction', 'deactivateAction', 'deleteAction'] as $method) {
            $this->assertTrue(strpos($source, $method) !== false);
        }
        $this->assertTrue(strpos($source, 'isPost()') !== false);
        $this->assertTrue(strpos($source, 'actionCsrf->isValid') !== false);
        $this->assertTrue(strpos($source, 'eval(') === false);
    }
}
