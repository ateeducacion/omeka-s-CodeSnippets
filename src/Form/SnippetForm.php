<?php

declare(strict_types=1);

namespace CodeSnippets\Form;

use CodeSnippets\Service\SnippetRepository;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;

class SnippetForm extends Form implements InputFilterProviderInterface
{
    public function init(): void
    {
        $this->setAttribute('method', 'post');

        $this->add([
            'name' => 'name',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Name', // @translate
                'info' => 'Display name of the snippet. Names do not need to be unique.', // @translate
            ],
            'attributes' => [
                'id' => 'code-snippets-name',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'description',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Description', // @translate
                'info' => 'Optional description of what this snippet does.', // @translate
            ],
            'attributes' => [
                'id' => 'code-snippets-description',
                'rows' => 3,
            ],
        ]);

        $this->add([
            'name' => 'code',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'PHP code', // @translate
                'info' => 'PHP run on each HTTP request when active. Opening <?php is optional.', // @translate
            ],
            'attributes' => [
                'id' => 'code-snippets-code',
                'class' => 'code-snippets-code',
                'required' => true,
                'rows' => 20,
                'spellcheck' => 'false',
                'autocapitalize' => 'off',
                'autocomplete' => 'off',
            ],
        ]);

        $this->add([
            'name' => 'priority',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Priority', // @translate
                'info' => 'Lower numbers execute first. Default is 10.', // @translate
            ],
            'attributes' => [
                'id' => 'code-snippets-priority',
                'step' => 1,
            ],
        ]);
        $this->get('priority')->setValue(SnippetRepository::DEFAULT_PRIORITY);

        $this->add([
            'name' => 'active',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Active', // @translate
                'info' => 'Execute this snippet on HTTP requests.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'id' => 'code-snippets-active',
            ],
        ]);

        $this->add([
            'type' => Element\Csrf::class,
            'name' => 'csrf',
            'options' => [
                'csrf_options' => [
                    'timeout' => 43200,
                ],
            ],
        ]);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'name' => [
                'required' => true,
                'filters' => [
                    ['name' => 'StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'min' => 1,
                            'max' => 255,
                        ],
                    ],
                ],
            ],
            'description' => [
                'required' => false,
            ],
            'code' => [
                'required' => true,
            ],
            'priority' => [
                'required' => false,
                'filters' => [
                    ['name' => 'ToInt'],
                ],
            ],
            'active' => [
                'required' => false,
            ],
            'csrf' => [
                'required' => true,
            ],
        ];
    }
}
