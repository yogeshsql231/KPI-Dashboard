<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Response emits JSON and then calls exit(), which cannot be exercised
 * in-process without terminating the test runner. Each case therefore runs the
 * call in an isolated PHP subprocess and asserts on the captured stdout and
 * exit status.
 */
final class ResponseTest extends TestCase
{
    /**
     * @return array{stdout: string, code: int, json: mixed}
     */
    private function runCall(string $call): array
    {
        $classFile = dirname(__DIR__, 2) . '/src/Response.php';
        $script = sprintf('require %s; %s', var_export($classFile, true), $call);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=0', '-r', $script],
            $descriptors,
            $pipes
        );
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'stdout' => $stdout,
            'code'   => $code,
            'json'   => json_decode($stdout, true),
        ];
    }

    public function testSuccessWrapsDataWithStatusSuccess(): void
    {
        $result = $this->runCall("Response::success(['otif' => 0.95]);");

        $this->assertSame(0, $result['code']);
        $this->assertSame(
            ['status' => 'success', 'data' => ['otif' => 0.95]],
            $result['json']
        );
    }

    public function testErrorProducesErrorEnvelope(): void
    {
        $result = $this->runCall("Response::error('Bad request');");

        $this->assertSame(
            ['status' => 'error', 'message' => 'Bad request'],
            $result['json']
        );
    }

    public function testErrorIncludesErrorsListWhenProvided(): void
    {
        $result = $this->runCall("Response::error('Validation failed', 422, ['a required', 'b invalid']);");

        $this->assertSame(
            [
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => ['a required', 'b invalid'],
            ],
            $result['json']
        );
    }

    public function testJsonDoesNotEscapeSlashesOrUnicode(): void
    {
        $result = $this->runCall("Response::json(['path' => '/a/b', 'name' => 'café']);");

        $this->assertStringContainsString('/a/b', $result['stdout']);
        $this->assertStringContainsString('café', $result['stdout']);
        $this->assertStringNotContainsString('\\/', $result['stdout']);
    }
}
