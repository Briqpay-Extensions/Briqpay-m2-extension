<?php
declare(strict_types=1);

namespace Briqpay\Payments\Setup\Patch\Data;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Keeps a store live that was live before the upgrade. Test mode now defaults to on for
 * a new install; a store that has credentials but never stored the setting (configured
 * from the command line or in env.php, say) used the live API so far, and still does.
 */
class KeepLiveModeFromUpgrade implements DataPatchInterface
{
    private const TEST_MODE = 'payment/briqpay/test_mode';

    private const CLIENT_ID = 'payment/briqpay/client_id';

    private ModuleDataSetupInterface $moduleDataSetup;

    private DeploymentConfig $deploymentConfig;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, DeploymentConfig $deploymentConfig)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');
        $configured = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])
                ->where('path = ?', self::CLIENT_ID)
                ->where("value IS NOT NULL AND value <> ''")
        );
        $testModeStored = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('path = ?', self::TEST_MODE)
        );
        // env.php / config.php settings, which Magento reads before the database.
        if ((string) $this->deploymentConfig->get('system/default/' . self::CLIENT_ID) !== '') {
            $configured++;
        }
        if ($this->deploymentConfig->get('system/default/' . self::TEST_MODE) !== null) {
            $testModeStored++;
        }
        if ($configured > 0 && $testModeStored === 0) {
            $connection->insert($table, [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => self::TEST_MODE,
                'value' => '0',
            ]);
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
