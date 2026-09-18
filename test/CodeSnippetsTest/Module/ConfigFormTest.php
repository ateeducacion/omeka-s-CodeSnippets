<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Module;

use CodeSnippets\Api\Adapter\SnippetAdapter;
use CodeSnippets\Module;
use CodeSnippetsTest\Support\ArrayServiceLocator;
use PHPUnit\Framework\TestCase;

/**
 * The module configuration exposes exactly one switch: whether the REST API
 * may write snippets. Reads are never affected by it.
 */
class ConfigFormTest extends TestCase
{
    /**
     * @return object
     */
    private function settings(array $stored = [])
    {
        return new class ($stored) {
            /** @var array<string, mixed> */
            public $stored;

            public function __construct(array $stored)
            {
                $this->stored = $stored;
            }

            public function get($key, $default = null)
            {
                return array_key_exists($key, $this->stored) ? $this->stored[$key] : $default;
            }

            public function set($key, $value): void
            {
                $this->stored[$key] = $value;
            }
        };
    }

    /**
     * @return object
     */
    private function renderer()
    {
        return new class {
            public function plugin($name)
            {
                if ($name === 'translate') {
                    return static function ($value) {
                        return $value;
                    };
                }
                return static function ($value) {
                    return htmlspecialchars((string) $value, ENT_QUOTES);
                };
            }
        };
    }

    /**
     * @param mixed $posted
     * @return object
     */
    private function controller($posted)
    {
        return new class ($posted) {
            /** @var mixed */
            private $posted;

            public function __construct($posted)
            {
                $this->posted = $posted;
            }

            public function params()
            {
                return new class ($this->posted) {
                    /** @var mixed */
                    private $posted;

                    public function __construct($posted)
                    {
                        $this->posted = $posted;
                    }

                    public function fromPost($name = null, $default = null)
                    {
                        return $this->posted;
                    }
                };
            }
        };
    }

    private function module($settings): Module
    {
        $module = new Module();
        $module->setServiceLocator(new ArrayServiceLocator(['Omeka\Settings' => $settings]));
        return $module;
    }

    public function testFormRendersUncheckedByDefault(): void
    {
        $html = $this->module($this->settings())->getConfigForm($this->renderer());

        $this->assertStringContainsString(SnippetAdapter::WRITE_SETTING, $html);
        $this->assertStringNotContainsString('checked="checked"', $html);
    }

    public function testFormRendersCheckedWhenWritesAreEnabled(): void
    {
        $settings = $this->settings([SnippetAdapter::WRITE_SETTING => true]);

        $html = $this->module($settings)->getConfigForm($this->renderer());

        $this->assertStringContainsString('checked="checked"', $html);
    }

    public function testSubmittingTheCheckboxEnablesWrites(): void
    {
        $settings = $this->settings();

        $result = $this->module($settings)->handleConfigForm($this->controller('1'));

        $this->assertTrue($result);
        $this->assertTrue($settings->stored[SnippetAdapter::WRITE_SETTING]);
    }

    public function testOmittingTheCheckboxDisablesWrites(): void
    {
        $settings = $this->settings([SnippetAdapter::WRITE_SETTING => true]);

        $this->module($settings)->handleConfigForm($this->controller(null));

        $this->assertFalse($settings->stored[SnippetAdapter::WRITE_SETTING]);
    }
}
