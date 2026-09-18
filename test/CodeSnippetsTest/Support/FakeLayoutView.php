<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeLayoutView implements \ArrayAccess
{
    /** @var string */
    public $content = 'MAIN';

    /** @var FakeHeadStyle */
    public $headStyle;

    /** @var bool */
    private $admin;

    public function __construct(bool $admin = false)
    {
        $this->admin = $admin;
        $this->headStyle = new FakeHeadStyle();
    }

    public function params(): self
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromRoute()
    {
        return $this->admin ? ['__ADMIN__' => true] : [];
    }

    public function vars(): self
    {
        return $this;
    }

    public function headStyle(): FakeHeadStyle
    {
        return $this->headStyle;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->$offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->$offset ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->$offset = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->$offset);
    }
}
