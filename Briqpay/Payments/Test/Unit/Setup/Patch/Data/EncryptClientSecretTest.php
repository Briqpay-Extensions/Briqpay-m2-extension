<?php
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Setup\Patch\Data;

use Briqpay\Payments\Setup\Patch\Data\EncryptClientSecret;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Setup\Patch\Data\EncryptClientSecret
 */
class EncryptClientSecretTest extends TestCase
{
    public function testPlainSecretsAreEncryptedAndEncryptedOrEmptyOnesLeftAlone(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['config_id' => 1, 'value' => 'plain-secret'],
            ['config_id' => 2, 'value' => '0:3:already-encrypted'],
            ['config_id' => 3, 'value' => ''],
            ['config_id' => 4, 'value' => 'store-secret'],
        ]);
        $updates = [];
        $connection->method('update')->willReturnCallback(
            static function (string $table, array $bind, array $where) use (&$updates): int {
                $updates[] = [$table, $bind, $where];

                return 1;
            }
        );
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static function (string $value): string {
            return '0:3:' . strrev($value);
        });

        (new EncryptClientSecret($setup, $encryptor))->apply();

        $this->assertSame([
            ['core_config_data', ['value' => '0:3:' . strrev('plain-secret')], ['config_id = ?' => 1]],
            ['core_config_data', ['value' => '0:3:' . strrev('store-secret')], ['config_id = ?' => 4]],
        ], $updates);
    }
}
