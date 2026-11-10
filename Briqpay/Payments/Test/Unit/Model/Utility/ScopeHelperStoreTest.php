<?php
/**
 * Credentials and test mode come from the order's store during admin operations and
 * webhooks, not from the store the request runs in.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\ScopeHelper
 */
class ScopeHelperStoreTest extends TestCase
{
    /**
     * @var ScopeHelper
     */
    private ScopeHelper $helper;

    protected function setUp(): void
    {
        $stores = [];
        foreach ([1 => 1, 2 => 1, 5 => 2] as $storeId => $websiteId) {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        // The request runs in store 1 (the admin or the default store view).
        $storeManager->method('getStore')->willReturnCallback(static function ($storeId = null) use ($stores) {
            return $stores[$storeId ?? 1];
        });
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $storeManager->method('getWebsite')->willReturn($website);
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static function ($path, $scope = null, $code = null) {
            return $path === 'payment/briqpay/client_id' && $scope === ScopeInterface::SCOPE_STORE
                ? 'client-of-store-' . $code
                : null;
        });

        $this->helper = new ScopeHelper($storeManager, $config);
    }

    public function testOrderStoreSettingsApplyInsideAndTheRequestStoreAfter(): void
    {
        $inside = $this->helper->inStore(5, function (): ?string {
            return $this->helper->getScopedConfigValue('payment/briqpay/client_id');
        });

        $this->assertSame('client-of-store-5', $inside);
        $this->assertSame('client-of-store-1', $this->helper->getScopedConfigValue('payment/briqpay/client_id'));
    }

    public function testRequestStoreIsRestoredWhenTheOperationFails(): void
    {
        try {
            $this->helper->inStore(5, self::failing());
        } catch (\RuntimeException $e) {
            $this->assertSame('client-of-store-1', $this->helper->getScopedConfigValue('payment/briqpay/client_id'));

            return;
        }
        $this->fail('No exception');
    }

    private static function failing(): callable
    {
        return static function (): void {
            throw new \RuntimeException('API down');
        };
    }

    public function testUnknownStoreKeepsTheCurrentOne(): void
    {
        $value = $this->helper->inStore(5, function (): ?string {
            return $this->helper->inStore(null, function (): ?string {
                return $this->helper->getScopedConfigValue('payment/briqpay/client_id');
            });
        });

        $this->assertSame('client-of-store-5', $value);
    }
}
