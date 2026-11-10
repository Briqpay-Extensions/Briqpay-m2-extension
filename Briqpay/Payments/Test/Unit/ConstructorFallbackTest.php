<?php
/**
 * Magento passes null for an optional constructor argument that di.xml does not set,
 * so a dependency declared `?Type $x = null` is only there when the constructor
 * falls back to the object manager for it, at construction or where it is used. Every
 * class in the module must.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ConstructorFallbackTest extends TestCase
{
    public function testEveryOptionalDependencyFallsBackToTheObjectManager(): void
    {
        $missing = [];
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match('/function __construct\((.*?)\)\s*\{(.*?)\n    \}/s', $source, $constructor)) {
                continue;
            }
            preg_match_all('/\?[\\\\\w]+\s+\$(\w+)\s*=\s*null/', $constructor[1], $optional);
            foreach ($optional[1] as $name) {
                // In the constructor, or lazily where it is used (`$this->x ?? ...`).
                if (!preg_match('/(\$this->)?' . $name . '\s*\?\?/', $source)) {
                    $missing[] = substr($file, strlen($this->moduleRoot()) + 1) . ': $' . $name;
                }
            }
        }

        $this->assertSame([], $missing, 'optional constructor arguments without a fallback');
    }

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->moduleRoot()));
        foreach ($iterator as $file) {
            $path = (string) $file;
            if (substr($path, -4) === '.php' && strpos($path, '/Test/') === false) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
