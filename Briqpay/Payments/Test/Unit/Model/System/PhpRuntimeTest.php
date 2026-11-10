<?php
/**
 * Which PHP versions lack the fixes for CVE-2026-109618 and CVE-2026-109619, and how a
 * merchant confirms a backported build.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\System;

use Briqpay\Payments\Model\System\Message\UnpatchedPhp;
use Briqpay\Payments\Model\System\PhpRuntime;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\System\PhpRuntime
 * @covers \Briqpay\Payments\Model\System\Message\UnpatchedPhp
 */
class PhpRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(PhpRuntime::BACKPORT_ENV);
    }

    /**
     * @dataProvider versionProvider
     */
    public function testRequiredUpdate(string $version, ?string $expected): void
    {
        $this->assertSame($expected, $this->runtime(false)->requiredUpdate($version));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public function versionProvider(): array
    {
        return [
            '8.1, never fixed' => ['8.1.30', '8.2.35'],
            '7.4, never fixed' => ['7.4.33', '8.2.35'],
            '8.2 before the fix' => ['8.2.20', '8.2.35'],
            '8.2 last unfixed' => ['8.2.34', '8.2.35'],
            '8.2 fixed' => ['8.2.35', null],
            '8.3 before the fix' => ['8.3.15', '8.3.36'],
            '8.3 fixed' => ['8.3.36', null],
            '8.4 before the fix' => ['8.4.26', '8.4.27'],
            '8.4 fixed' => ['8.4.27', null],
            '8.4 later' => ['8.4.30', null],
            '8.5 before the fix' => ['8.5.11', '8.5.12'],
            '8.5 fixed' => ['8.5.12', null],
            'later minor' => ['8.6.0', null],
            'distribution suffix, unfixed number' => ['8.3.6-1ubuntu1.2', '8.3.36'],
            'distribution suffix, fixed number' => ['8.4.27-1+deb12', null],
            'not a version' => ['unknown', null],
        ];
    }

    public function testBackportIsConfirmedInTheSettings(): void
    {
        $this->assertTrue($this->runtime(true)->backportConfirmed());
        $this->assertFalse($this->runtime(false)->backportConfirmed());
    }

    public function testBackportIsConfirmedByTheEnvironment(): void
    {
        putenv(PhpRuntime::BACKPORT_ENV . '=1');

        $this->assertTrue($this->runtime(false)->backportConfirmed());
    }

    public function testWarningFollowsTheRunningPhpAndTheConfirmation(): void
    {
        $unpatched = $this->runtime(false)->requiredUpdate(PHP_VERSION) !== null;

        $this->assertSame($unpatched, $this->runtime(false)->needsWarning());
        $this->assertFalse($this->runtime(true)->needsWarning());
    }

    public function testAdminMessageNamesTheVersionTheAdvisoryAndIsCritical(): void
    {
        $message = new UnpatchedPhp($this->runtime(false));

        $this->assertStringContainsString(PHP_VERSION, $message->getText());
        $this->assertStringContainsString(PhpRuntime::ADVISORY, $message->getText());
        $this->assertSame(UnpatchedPhp::SEVERITY_CRITICAL, $message->getSeverity());
        $this->assertSame($this->runtime(false)->needsWarning(), $message->isDisplayed());
        $this->assertFalse((new UnpatchedPhp($this->runtime(true)))->isDisplayed());
    }

    private function runtime(bool $confirmed): PhpRuntime
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->with(PhpRuntime::BACKPORT_CONFIG_PATH)->willReturn($confirmed);

        return new PhpRuntime($config);
    }
}
