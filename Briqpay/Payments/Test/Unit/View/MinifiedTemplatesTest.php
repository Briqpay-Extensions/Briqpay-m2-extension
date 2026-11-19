<?php
/**
 * In production mode Magento serves each template from a minified copy that puts the
 * whole file on one line, keeping only unquoted heredocs as they are. Developer mode
 * serves the source, so a template that breaks only once minified passes every local
 * check. These tests minify with Magento's own minifier and check the copy.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\View;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\View\Template\Html\Minifier;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") the stub renderer's renderTag() takes arguments it ignores
 */
class MinifiedTemplatesTest extends TestCase
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../../view';

    /**
     * @var string
     */
    private string $magentoRoot;

    /**
     * @var Minifier
     */
    private Minifier $minifier;

    protected function setUp(): void
    {
        $this->magentoRoot = sys_get_temp_dir() . '/briqpay-minified-' . bin2hex(random_bytes(6));
        $driverPool = new DriverPool();
        $readFactory = new ReadFactory($driverPool);
        $filesystem = new Filesystem(
            new DirectoryList($this->magentoRoot),
            $readFactory,
            new WriteFactory($driverPool)
        );
        $this->minifier = new Minifier($filesystem, $readFactory);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->magentoRoot);
    }

    /**
     * @dataProvider templateProvider
     */
    public function testMinifiedTemplateIsValidPhp(string $template): void
    {
        $minified = $this->minify($template);

        try {
            $tokens = token_get_all($minified, TOKEN_PARSE);
        } catch (\ParseError $error) {
            $this->fail(sprintf(
                "%s does not parse once minified for production mode: %s\n%s",
                basename($template),
                $error->getMessage(),
                $minified
            ));
        }

        $this->assertNotEmpty($tokens);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateProvider(): array
    {
        $cases = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            (string) realpath(self::TEMPLATE_ROOT),
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($files as $file) {
            if ($file->getExtension() === 'phtml') {
                $path = $file->getPathname();
                $cases[substr($path, strlen((string) realpath(self::TEMPLATE_ROOT)) + 1)] = [$path];
            }
        }
        ksort($cases);

        return $cases;
    }

    public function testResetCartRendersTheSameScriptOnceMinified(): void
    {
        $source = (string) realpath(self::TEMPLATE_ROOT . '/frontend/templates/reset-cart.phtml');

        $fromSource = $this->renderedScript($source);

        $this->assertStringContainsString("customerData.reload(['cart'], true);", $fromSource);
        $minifiedCopy = $this->magentoRoot . '/reset-cart.minified.phtml';
        file_put_contents($minifiedCopy, $this->minify($source));
        $this->assertSame($fromSource, $this->renderedScript($minifiedCopy));
    }

    /**
     * The minifier reads only files under the Magento root, so it gets a copy there.
     */
    private function minify(string $template): string
    {
        $copy = $this->magentoRoot . '/app/code/Briqpay/Payments/view/' . basename($template);
        if (!is_dir(dirname($copy))) {
            mkdir(dirname($copy), 0777, true);
        }
        copy($template, $copy);

        return (string) file_get_contents($this->minifier->getMinified($copy));
    }

    /**
     * Includes the template with a renderer that captures the script it is handed.
     */
    private function renderedScript(string $template): string
    {
        $secureRenderer = new class {
            /**
             * @var string
             */
            public string $script = '';

            /**
             * @param array<string, string> $attributes
             */
            public function renderTag(
                string $tag,
                array $attributes,
                ?string $content = null,
                bool $escape = true
            ): string {
                $this->script = (string) $content;

                return '';
            }
        };

        ob_start();
        (static function (string $template, object $secureRenderer): void {
            include $template;
        })($template, $secureRenderer);
        ob_end_clean();

        return $secureRenderer->script;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
