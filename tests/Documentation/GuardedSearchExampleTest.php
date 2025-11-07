<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Documentation;

use PHPUnit\Framework\TestCase;

use function sprintf;

use const PHP_BINARY;
use const PHP_EOL;

final class GuardedSearchExampleTest extends TestCase
{
    private const DOCUMENT_PATH = __DIR__.'/../../docs/guarded-search-example.md';

    public function test_documentation_example_executes_successfully(): void
    {
        $document = file_get_contents(self::DOCUMENT_PATH);
        self::assertNotFalse($document, 'Failed to read guarded search documentation.');

        $matches = [];
        self::assertSame(
            1,
            preg_match('/```php\s*(.*?)```/s', $document, $matches),
            'Could not extract PHP example from guarded search documentation.',
        );

        $snippet = trim($matches[1]).PHP_EOL;
        $bootstrap = sprintf('require %s;', var_export($this->autoloadPath(), true));

        $script = implode(PHP_EOL, [
            '<?php',
            'declare(strict_types=1);',
            $bootstrap,
            $snippet,
        ]);

        $temporaryFile = $this->createTemporaryScript($script);

        try {
            [$exitCode, $stdout, $stderr] = $this->runPhpScript($temporaryFile);
        } finally {
            @unlink($temporaryFile);
        }

        self::assertSame(0, $exitCode, $stderr ?: 'Documentation example exited with a non-zero status.');
        self::assertStringContainsString('Found path with residual tolerance', $stdout);
        self::assertStringContainsString('Explored', $stdout);
    }

    private function autoloadPath(): string
    {
        $path = realpath(__DIR__.'/../../vendor/autoload.php');
        self::assertNotFalse($path, 'Failed to locate Composer autoload file.');

        return $path;
    }

    private function createTemporaryScript(string $script): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'guarded-search-example-');
        self::assertNotFalse($temporaryFile, 'Failed to create temporary script for documentation example.');

        $bytesWritten = file_put_contents($temporaryFile, $script);
        self::assertNotFalse($bytesWritten, 'Failed to write documentation example to temporary script.');

        return $temporaryFile;
    }

    /**
     * @return array{int,string,string}
     */
    private function runPhpScript(string $scriptPath): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptPath);

        $process = proc_open($command, $descriptorSpec, $pipes);
        self::assertIsResource($process, 'Failed to spawn PHP process for documentation example.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            $exitCode,
            false !== $stdout ? $stdout : '',
            false !== $stderr ? $stderr : '',
        ];
    }
}
