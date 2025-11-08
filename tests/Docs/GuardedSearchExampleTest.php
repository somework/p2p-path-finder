<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function error_get_last;
use function file_put_contents;
use function ini_get;
use function sprintf;

use const PHP_EOL;

/**
 * @coversNothing
 */
final class GuardedSearchExampleTest extends TestCase
{
    private const DOCUMENTATION_PATH = __DIR__.'/../../docs/guarded-search-example.md';

    public function test_documentation_example_produces_route(): void
    {
        $script = $this->createScriptFromDocumentation();

        $output = $this->executeDocumentationScript($script);

        self::assertMatchesRegularExpression(
            '/Found path with residual tolerance [0-9.]+% and \d+ segments/i',
            $output,
            'Guarded search walkthrough should discover at least one path.',
        );

        self::assertStringContainsString(
            'Explored ',
            $output,
            'Guard metrics report should be printed by the walkthrough.',
        );
    }

    private function createScriptFromDocumentation(): string
    {
        $contents = file_get_contents(self::DOCUMENTATION_PATH);

        if (false === $contents) {
            throw new RuntimeException('Unable to read guarded search documentation.');
        }

        $code = $this->extractPhpCodeBlock($contents);

        return sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace {
                %s
                }
                PHP,
            rtrim($code).PHP_EOL,
        );
    }

    private function extractPhpCodeBlock(string $markdown): string
    {
        if (1 !== preg_match('/```php\s+(.*?)```/s', $markdown, $matches)) {
            throw new RuntimeException('Guarded search documentation is missing a PHP example block.');
        }

        return $matches[1];
    }

    private function executeDocumentationScript(string $script): string
    {
        $path = tempnam(sys_get_temp_dir(), 'guarded-search-doc-');

        if (false === $path) {
            throw new RuntimeException('Unable to create temporary script for documentation walkthrough.');
        }

        $bytesWritten = @file_put_contents($path, $script);

        if (false === $bytesWritten) {
            $error = error_get_last();

            throw new RuntimeException(sprintf('Unable to write documentation walkthrough script to "%s": %s', $path, $error['message'] ?? 'unknown error'));
        }

        $previousZendAssertions = ini_get('zend.assertions');
        $previousAssertActive = ini_get('assert.active');
        $previousAssertException = ini_get('assert.exception');
        $previousAssertWarning = ini_get('assert.warning');

        ini_set('zend.assertions', '1');
        ini_set('assert.active', '1');
        ini_set('assert.exception', '1');
        ini_set('assert.warning', '1');

        $outputBufferLevel = ob_get_level();
        ob_start();

        try {
            include $path;

            $output = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $outputBufferLevel) {
                ob_end_clean();
            }

            if (false !== $previousZendAssertions) {
                ini_set('zend.assertions', (string) $previousZendAssertions);
            }

            if (false !== $previousAssertActive) {
                ini_set('assert.active', (string) $previousAssertActive);
            }

            if (false !== $previousAssertException) {
                ini_set('assert.exception', (string) $previousAssertException);
            }

            if (false !== $previousAssertWarning) {
                ini_set('assert.warning', (string) $previousAssertWarning);
            }

            @unlink($path);
        }

        return $output;
    }
}
