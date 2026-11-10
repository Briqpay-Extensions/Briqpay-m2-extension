<?php
declare(strict_types=1);

namespace Briqpay\Payments\Setup\Patch\Data;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Encrypts a stored client secret that is not encrypted yet, in every scope. The setting
 * is saved encrypted, and the API client decrypts what carries Magento's encryption
 * prefix; a value already encrypted is left as it is.
 */
class EncryptClientSecret implements DataPatchInterface
{
    public const PATH = 'payment/briqpay/shared_secret';

    private ModuleDataSetupInterface $moduleDataSetup;

    private EncryptorInterface $encryptor;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, EncryptorInterface $encryptor)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->encryptor = $encryptor;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');
        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['config_id', 'value'])->where('path = ?', self::PATH)
        );
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value === '' || preg_match('/^\\d+:\\d+:/', $value)) {
                continue;
            }
            $connection->update(
                $table,
                ['value' => $this->encryptor->encrypt($value)],
                ['config_id = ?' => (int) $row['config_id']]
            );
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases()
    {
        return [];
    }
}
