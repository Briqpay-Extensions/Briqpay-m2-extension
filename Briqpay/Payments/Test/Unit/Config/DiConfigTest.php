<?php
/**
 * The module's DI configuration changes only its own classes and Magento's documented
 * extension points, never a Magento class for the whole store.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class DiConfigTest extends TestCase
{
    /**
     * Magento types that exist to be extended through their arguments.
     */
    private const EXTENSION_POINTS = [
        'Magento\Checkout\Model\CompositeConfigProvider',
        'Magento\Framework\Console\CommandList',
        'Magento\Framework\Notification\MessageList',
    ];

    /**
     * @dataProvider diFileProvider
     */
    public function testNoPreferenceReplacesAMagentoClass(string $file): void
    {
        foreach ($this->load($file)->xpath('//preference') ?: [] as $preference) {
            $this->assertStringStartsNotWith('Magento\\', (string) $preference['for'], $file);
        }
    }

    /**
     * @dataProvider diFileProvider
     */
    public function testMagentoTypesAreConfiguredOnlyAtExtensionPoints(string $file): void
    {
        foreach ($this->load($file)->xpath('//type') ?: [] as $type) {
            $name = (string) $type['name'];
            if (strpos($name, 'Magento\\') === 0) {
                $this->assertContains($name, self::EXTENSION_POINTS, $file);
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function diFileProvider(): array
    {
        $etc = dirname(__DIR__, 3) . '/etc';
        $files = array_merge(glob($etc . '/di.xml') ?: [], glob($etc . '/*/di.xml') ?: []);
        $cases = [];
        foreach ($files as $file) {
            $cases[substr($file, strlen($etc) + 1)] = [$file];
        }

        return $cases;
    }

    private function load(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_file($file);
        $this->assertNotFalse($xml, $file);

        return $xml;
    }
}
