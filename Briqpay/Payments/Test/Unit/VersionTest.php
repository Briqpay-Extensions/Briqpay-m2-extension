<?php
/**
 * The module's version is declared twice: in composer.json, which cuts releases, and in
 * etc/module.xml, which Magento stores and the API client reports to Briqpay in its
 * User-Agent. They must name the same release.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class VersionTest extends TestCase
{
    public function testModuleXmlCarriesTheComposerVersion(): void
    {
        $root = dirname(__DIR__, 4);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $moduleXml = simplexml_load_string((string) file_get_contents($root . '/Briqpay/Payments/etc/module.xml'));

        $this->assertNotFalse($moduleXml);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-dev)?$/', (string) $composer['version']);
        $this->assertSame(
            preg_replace('/-dev$/', '', (string) $composer['version']),
            (string) $moduleXml->module['setup_version'],
            'etc/module.xml setup_version must be the composer.json version without -dev'
        );
    }
}
