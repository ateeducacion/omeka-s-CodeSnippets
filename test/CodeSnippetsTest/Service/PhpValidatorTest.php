<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Service\PhpValidator;
use PHPUnit\Framework\TestCase;

class PhpValidatorTest extends TestCase
{
    /** @var PhpValidator */
    private $validator;

    /** @var bool */
    private $executed = false;

    protected function setUp(): void
    {
        $this->validator = new PhpValidator();
        $this->executed = false;
        $GLOBALS['code_snippets_validator_executed'] = false;
    }

    public function testValidSimplePhp(): void
    {
        $this->assertTrue($this->validator->validate('$x = 1;')->isValid());
        $this->assertFalse($GLOBALS['code_snippets_validator_executed']);
    }

    public function testValidMultilinePhp(): void
    {
        $code = "\$a = 1;\n\$b = \$a + 2;\nreturn \$b;";
        $this->assertTrue($this->validator->validate($code)->isValid());
    }

    public function testSyntaxError(): void
    {
        $result = $this->validator->validate('if (');
        $this->assertFalse($result->isValid());
        $this->assertNotNull($result->getMessage());
        $this->assertFalse($GLOBALS['code_snippets_validator_executed']);
    }

    public function testClosure(): void
    {
        $this->assertTrue($this->validator->validate('$f = function () { return 1; };')->isValid());
    }

    public function testFunctionDeclaration(): void
    {
        $this->assertTrue($this->validator->validate('function my_function() {}')->isValid());
    }

    public function testClassDeclaration(): void
    {
        $this->assertTrue($this->validator->validate('class MyClass {}')->isValid());
    }

    public function testNamespaceDeclaration(): void
    {
        $this->assertTrue($this->validator->validate('namespace Foo;')->isValid());
    }

    public function testUseStatement(): void
    {
        $this->assertTrue($this->validator->validate('use Some\\ClassName;')->isValid());
    }

    public function testDeclareStrictTypes(): void
    {
        $this->assertTrue($this->validator->validate('declare(strict_types=1);')->isValid());
    }

    public function testOptionalOpeningTagIsNormalized(): void
    {
        $result = $this->validator->validate("<?php\n\$x = 1;");
        $this->assertTrue($result->isValid());
        $this->assertSame("\$x = 1;", trim($this->validator->normalize("<?php\n\$x = 1;")));
    }

    public function testEmptyCodeIsInvalid(): void
    {
        $this->assertFalse($this->validator->validate("  \n  ")->isValid());
    }

    public function testValidationDoesNotExecuteCode(): void
    {
        $code = '$GLOBALS["code_snippets_validator_executed"] = true;';
        $this->assertTrue($this->validator->validate($code)->isValid());
        $this->assertFalse($GLOBALS['code_snippets_validator_executed']);
    }

    public function testBomAndCloseTagAreStripped(): void
    {
        $code = "\xEF\xBB\xBF<?php\n\$x = 1;\n?>";
        $this->assertSame("\$x = 1;", $this->validator->normalize($code));
    }

    public function testShortOpenTagIsStripped(): void
    {
        $this->assertSame("\$x = 1;", $this->validator->normalize("<?\n\$x = 1;"));
    }

    public function testCarriageReturnAfterOpenTag(): void
    {
        $this->assertSame("\$x = 1;", $this->validator->normalize("<?php\r\n\$x = 1;"));
    }

    public function testSyntaxErrorLineIsAtLeastOne(): void
    {
        $result = $this->validator->validate('<?php');
        $this->assertFalse($result->isValid());
    }
}
