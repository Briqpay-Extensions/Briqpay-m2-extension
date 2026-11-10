<?php
/**
 * bin/magento briqpay:php:check exits 1 only for a PHP that lacks the fixes and has no
 * confirmed backport.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Console\Command;

use Briqpay\Payments\Console\Command\PhpCheck;
use Briqpay\Payments\Model\System\PhpRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Briqpay\Payments\Console\Command\PhpCheck
 */
class PhpCheckTest extends TestCase
{
    public function testFixedPhpPasses(): void
    {
        $tester = $this->check(null, false);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('has the fixes', $tester->getDisplay());
    }

    public function testUnfixedPhpFailsAndNamesTheUpdate(): void
    {
        $tester = $this->check('8.4.27', false);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Update PHP to 8.4.27 or later', $tester->getDisplay());
    }

    public function testConfirmedBackportPasses(): void
    {
        $tester = $this->check('8.4.27', true);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('backported fix', $tester->getDisplay());
    }

    private function check(?string $update, bool $confirmed): CommandTester
    {
        $runtime = $this->createMock(PhpRuntime::class);
        $runtime->method('requiredUpdate')->willReturn($update);
        $runtime->method('backportConfirmed')->willReturn($confirmed);
        $tester = new CommandTester(new PhpCheck($runtime));
        $tester->execute([]);

        return $tester;
    }
}
