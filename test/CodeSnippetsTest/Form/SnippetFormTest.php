<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Form;

use CodeSnippets\Form\SnippetForm;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetScope;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Number;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Textarea;
use PHPUnit\Framework\TestCase;

class SnippetFormTest extends TestCase
{
    public function testRequiredFieldsExist(): void
    {
        $form = new SnippetForm('snippet');
        $form->init();

        $this->assertInstanceOf(Text::class, $form->get('name'));
        $this->assertInstanceOf(Textarea::class, $form->get('description'));
        $this->assertInstanceOf(Textarea::class, $form->get('code'));
        $this->assertInstanceOf(Number::class, $form->get('priority'));
        $this->assertInstanceOf(Select::class, $form->get('run_scope'));
        $this->assertSame(SnippetScope::DEFAULT, $form->get('run_scope')->getValue());
        $this->assertInstanceOf(Checkbox::class, $form->get('active'));
        $this->assertInstanceOf(Csrf::class, $form->get('csrf'));
        $this->assertSame(
            SnippetRepository::DEFAULT_PRIORITY,
            (int) $form->get('priority')->getValue()
        );
    }

    public function testInputFilterSpecification(): void
    {
        $form = new SnippetForm('snippet');
        $form->init();
        $spec = $form->getInputFilterSpecification();
        $this->assertTrue($spec['name']['required']);
        $this->assertTrue($spec['code']['required']);
        $this->assertFalse($spec['description']['required']);
        $this->assertFalse($spec['priority']['required']);
        $this->assertFalse($spec['active']['required']);
        $this->assertTrue($spec['csrf']['required']);
        $filter = $form->getInputFilter();
        $this->assertTrue($filter->has('name'));
        $this->assertTrue($filter->has('code'));
    }
}
