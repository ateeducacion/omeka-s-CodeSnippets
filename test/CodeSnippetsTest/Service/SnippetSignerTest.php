<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Exception\SnippetIntegrityException;
use CodeSnippets\Service\SnippetSigner;
use PHPUnit\Framework\TestCase;

class SnippetSignerTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    public static function configurations(): array
    {
        return [
            [null, SnippetSigner::DISABLED],
            ['', SnippetSigner::DISABLED],
            [self::KEY, SnippetSigner::ENABLED],
            [str_repeat('x', 31), SnippetSigner::MISCONFIGURED],
            ['0', SnippetSigner::MISCONFIGURED],
            [false, SnippetSigner::MISCONFIGURED],
            [0, SnippetSigner::MISCONFIGURED],
            [[], SnippetSigner::MISCONFIGURED],
            [new \stdClass(), SnippetSigner::MISCONFIGURED],
        ];
    }

    /** @dataProvider configurations */
    public function testConfigurationStates($key, string $state): void
    {
        $signer = new SnippetSigner($key);
        $this->assertSame($state, $signer->state());
        $this->assertSame($state === SnippetSigner::DISABLED, $signer->verify($this->snippet()));
    }

    public function testAbsentAndMalformedConfiguration(): void
    {
        $this->assertSame(SnippetSigner::DISABLED, SnippetSigner::fromConfig([])->state());
        $this->assertSame(SnippetSigner::DISABLED, SnippetSigner::fromConfig(['code_snippets' => []])->state());
        $this->assertSame(SnippetSigner::MISCONFIGURED, SnippetSigner::fromConfig(false)->state());
        $this->assertSame(
            SnippetSigner::MISCONFIGURED,
            SnippetSigner::fromConfig(['code_snippets' => 'bad'])->state()
        );
    }

    public function testCanonicalPayloadAndDomainAreDeterministic(): void
    {
        $signer = new SnippetSigner(self::KEY);
        $json = '{"id":7,"name":"Café / test","description":null,"code":"$x = 1;",'
            . '"priority":10,"active":true,"run_scope":"global"}';
        $this->assertSame("omeka-s-code-snippets\0signature-v1\0", SnippetSigner::DOMAIN);
        $expected = 'hmac-sha256:v1:' . hash_hmac('sha256', SnippetSigner::DOMAIN . $json, self::KEY);
        $this->assertSame($expected, $signer->sign($this->snippet()));
        $row = array_reverse($this->snippet(), true);
        $row['id'] = '7';
        $row['priority'] = '10';
        $row['active'] = 1;
        $row['signature'] = $expected;
        $this->assertSame($expected, $signer->sign($row));
        $this->assertTrue($signer->verify($row));
    }

    public static function tampering(): array
    {
        return [
            ['id', 8], ['name', 'Other'], ['description', 'Other'], ['description', ''],
            ['code', '$x = 2;'], ['priority', 11], ['active', false], ['run_scope', 'front-end'],
        ];
    }

    /** @dataProvider tampering */
    public function testEveryTrustedFieldIsBound(string $field, $value): void
    {
        $signer = new SnippetSigner(self::KEY);
        $row = $this->snippet();
        $row['signature'] = $signer->sign($row);
        $row[$field] = $value;
        $this->assertNotSame($row['signature'], $signer->sign($row));
        $this->assertFalse($signer->verify($row));
    }

    public function testDiagnosticsAndTimestampsAreNotSigned(): void
    {
        $signer = new SnippetSigner(self::KEY);
        $row = $this->snippet();
        $row['signature'] = $signer->sign($row);
        $fields = ['created', 'modified', 'last_error_type', 'last_error_message', 'last_error_line', 'last_error_at'];
        foreach ($fields as $key) {
            $row[$key] = 'changed';
        }
        $this->assertTrue($signer->verify($row));
    }

    public static function malformedSignatures(): array
    {
        return [
            [null], [''], [false], [[]], ['forged'],
            ['hmac-sha256:v2:' . str_repeat('a', 64)],
            ['hmac-sha256:v1:' . str_repeat('A', 64)],
            ['hmac-sha256:v1:' . str_repeat('0', 63)],
            ['hmac-sha256:v1:' . str_repeat('0', 64) . "\n"],
            ['hmac-sha256:v1:' . str_repeat('0', 64)],
        ];
    }

    /** @dataProvider malformedSignatures */
    public function testMalformedAndUnsupportedSignaturesFail($signature): void
    {
        $row = $this->snippet();
        $row['signature'] = $signature;
        $this->assertFalse((new SnippetSigner(self::KEY))->verify($row));
    }

    public function testRotationAndUnmodifiedKeyBytes(): void
    {
        $signer = new SnippetSigner(self::KEY);
        $row = $this->snippet();
        $row['signature'] = $signer->sign($row);
        $rotated = new SnippetSigner(' ' . self::KEY . "\n");
        $this->assertNotSame($row['signature'], $rotated->sign($row));
        $this->assertFalse($rotated->verify($row));
    }

    public function testEncodingFailureIsClosedAndGeneric(): void
    {
        $signer = new SnippetSigner(self::KEY);
        $row = $this->snippet();
        $row['signature'] = $signer->sign($row);
        $row['name'] = "\xff";
        $this->assertFalse($signer->verify($row));
        try {
            $signer->sign($row);
            $this->fail('Expected signing failure.');
        } catch (SnippetIntegrityException $e) {
            $this->assertSame('Snippet integrity signing failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    public function testUnavailableSignerCannotSign(): void
    {
        $this->expectException(SnippetIntegrityException::class);
        (new SnippetSigner('short'))->sign($this->snippet());
    }

    public function testDebugAndJsonDoNotRevealSecret(): void
    {
        $signer = new SnippetSigner(self::KEY);
        ob_start();
        var_dump($signer);
        $debug = ob_get_clean();
        $this->assertStringNotContainsString(self::KEY, $debug);
        $this->assertStringNotContainsString(self::KEY, json_encode($signer));
        $this->expectException(SnippetIntegrityException::class);
        serialize($signer);
    }

    public function testVerificationUsesConstantTimeComparison(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/SnippetSigner.php');
        $this->assertStringContainsString('hash_equals(', $source);
    }

    private function snippet(): array
    {
        return [
            'id' => 7, 'name' => 'Café / test', 'description' => null, 'code' => '$x = 1;',
            'priority' => 10, 'active' => true, 'run_scope' => 'global',
        ];
    }
}
