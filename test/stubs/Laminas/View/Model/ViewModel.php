<?php

namespace Laminas\View\Model;

class ViewModel
{
    /** @var array<string, mixed> */
    public $variables;

    /** @var string|null */
    public $template;

    /** @var bool */
    public $terminal = false;

    public function __construct($variables = [])
    {
        $this->variables = $variables;
    }

    public function setTemplate($template)
    {
        $this->template = $template;
        return $this;
    }

    public function setTerminal($terminal)
    {
        $this->terminal = (bool) $terminal;
        return $this;
    }
}
