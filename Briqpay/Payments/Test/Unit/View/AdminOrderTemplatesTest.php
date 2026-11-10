<?php
/**
 * The admin order view shows values the shopper typed (company name, organisation
 * number) and values from the payment provider; each must reach the page escaped.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\View;

use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") the stub block's __call() takes arguments it ignores
 */
class AdminOrderTemplatesTest extends TestCase
{
    private const HOSTILE = '<img src=x onerror=alert(1)>';

    /**
     * @dataProvider templateProvider
     */
    public function testEveryValueIsEscaped(string $template): void
    {
        $html = $this->render($template);

        $this->assertStringNotContainsString(self::HOSTILE, $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function templateProvider(): array
    {
        return [
            'company' => ['business_custom_info.phtml'],
            'transaction' => ['custom_info.phtml'],
            'strong authentication' => ['strong_auth_custom_info.phtml'],
        ];
    }

    public function testBackofficeLinkCannotRunScript(): void
    {
        $html = $this->render('custom_info.phtml', 'javascript:alert(1)');

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    private function render(string $template, string $url = 'https://app.briqpay.com/x'): string
    {
        $block = new class (self::HOSTILE, $url) extends DataObject {
            /**
             * @var string
             */
            private string $value;

            /**
             * @var string
             */
            private string $url;

            public function __construct(string $value, string $url)
            {
                parent::__construct();
                $this->value = $value;
                $this->url = $url;
            }

            /**
             * @param string $method
             * @param array<int, mixed> $args
             * @return mixed
             */
            public function __call($method, $args)
            {
                if ($method === 'getBriqpayBackofficeUrl') {
                    return $this->url;
                }
                if (strpos($method, 'has') === 0 || strpos($method, 'is') === 0) {
                    return true;
                }

                return $this->value;
            }
        };
        $escaper = new Escaper();
        // Escaper loads Magento's inline translator lazily; it only strips translation markers here.
        $inline = $this->createMock(\Magento\Framework\Translate\InlineInterface::class);
        $inline->method('processResponseBody')->willReturnArgument(0);
        $property = new \ReflectionProperty(Escaper::class, 'translateInline');
        $property->setValue($escaper, $inline);
        ob_start();
        (static function (string $file, $block, Escaper $escaper): void {
            include $file;
        })(__DIR__ . '/../../../view/adminhtml/templates/order/view/' . $template, $block, $escaper);

        return (string) ob_get_clean();
    }
}
