<?php

namespace Laminas\View\Model;

class ViewModel
{
    /** @var array<string, mixed> */
    public $variables;

    public function __construct($variables = [])
    {
        $this->variables = $variables;
    }
}
