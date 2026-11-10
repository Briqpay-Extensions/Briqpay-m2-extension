<?php
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Setup\Patch\Data;

use Briqpay\Payments\Setup\Patch\Data\KeepLiveModeFromUpgrade;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

/**
 * A store that ran live before the upgrade (credentials, no test-mode setting stored)
 * stays live although test mode now defaults to on.
 *
 * @covers \Briqpay\Payments\Setup\Patch\Data\KeepLiveModeFromUpgrade
 */
class KeepLiveModeFromUpgradeTest extends TestCase
{
    /**
     * @dataProvider stores
     * @param array<string, string|null> $deployment
     */
    public function testLiveModeIsStoredOnlyForAStoreThatRanLive(
        int $clientIdRows,
        int $testModeRows,
        array $deployment,
        bool $stored
    ): void {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls($clientIdRows, $testModeRows);
        $inserts = [];
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $bind) use (&$inserts): int {
                $inserts[] = [$table, $bind];

                return 1;
            }
        );
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn (string $path) => $deployment[$path] ?? null);

        (new KeepLiveModeFromUpgrade($setup, $config))->apply();

        $expected = $stored ? [[
            'core_config_data',
            ['scope' => 'default', 'scope_id' => 0, 'path' => 'payment/briqpay/test_mode', 'value' => '0'],
        ]] : [];
        $this->assertSame($expected, $inserts);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: array<string, string|null>, 3: bool}>
     */
    public static function stores(): array
    {
        return [
            'credentials in the database, no test mode' => [1, 0, [], true],
            'credentials in env.php, no test mode' => [
                0, 0, ['system/default/payment/briqpay/client_id' => 'id'], true,
            ],
            'test mode stored' => [1, 1, [], false],
            'test mode in env.php' => [1, 0, ['system/default/payment/briqpay/test_mode' => '1'], false],
            'a new install' => [0, 0, [], false],
        ];
    }
}
