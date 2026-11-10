<?php
/**
 * bin/magento briqpay:php:check: whether this PHP has the fixes PhpRuntime checks for.
 *
 * Exits 1 when PHP needs updating and no backport was confirmed, so it can run in a
 * deploy script. The CLI can run on another PHP build than the web server, so run it
 * with the same PHP binary as PHP-FPM when they differ.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Console\Command;

use Briqpay\Payments\Model\System\PhpRuntime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PhpCheck extends Command
{
    /**
     * @var PhpRuntime
     */
    private PhpRuntime $phpRuntime;

    /**
     * @param PhpRuntime $phpRuntime
     */
    public function __construct(PhpRuntime $phpRuntime)
    {
        $this->phpRuntime = $phpRuntime;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('briqpay:php:check')
            ->setDescription('Checks whether this PHP has the fixes for ' . PhpRuntime::ADVISORY . '.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $update = $this->phpRuntime->requiredUpdate(PHP_VERSION);
        if ($update === null) {
            $output->writeln(sprintf('<info>PHP %s has the fixes for %s.</info>', PHP_VERSION, PhpRuntime::ADVISORY));

            return 0;
        }
        if ($this->phpRuntime->backportConfirmed()) {
            $output->writeln(sprintf(
                '<comment>PHP %s: a backported fix for %s is confirmed for this build.</comment>',
                PHP_VERSION,
                PhpRuntime::ADVISORY
            ));

            return 0;
        }
        $output->writeln(sprintf(
            '<error>PHP %s is affected by %s. Update PHP to %s or later, or confirm a backported fix '
            . '(setting %s, or the environment variable %s=1).</error>',
            PHP_VERSION,
            PhpRuntime::ADVISORY,
            $update,
            PhpRuntime::BACKPORT_CONFIG_PATH,
            PhpRuntime::BACKPORT_ENV
        ));

        return 1;
    }
}
