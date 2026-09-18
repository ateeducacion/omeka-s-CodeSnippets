<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeHeadStyle
{
    /** @var array<int, string> */
    public $css = [];

    public function appendStyle($css): self
    {
        $this->css[] = (string) $css;
        return $this;
    }
}
