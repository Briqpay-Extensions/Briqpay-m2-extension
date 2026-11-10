<?php

namespace Briqpay\Payments\Rest;

use Magento\Framework\HTTP\Client\Curl;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;

class ApiClient
{
    /**
     * What request() answers when the payment provider cannot cancel the order.
     */
    public const CANCEL_NOT_SUPPORTED = 'Cancel is not supported by the payment provider';

    /**
     * Seconds to wait for Briqpay; a capture or refund waits for the payment provider.
     */
    private const TIMEOUT = 60;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ModuleListInterface
     */
    protected $moduleList;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    protected $scopeHelper;

    private ?EncryptorInterface $encryptor;

    /**
     * ApiClient constructor.
     *
     * @param Curl $curl
     * @param Logger $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param ModuleListInterface $moduleList
     * @param ProductMetadataInterface $productMetadata
     * @param StoreManagerInterface $storeManager
     * @param ScopeHelper $scopeHelper
     * @param EncryptorInterface|null $encryptor
     */
    public function __construct(
        Curl $curl,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        ModuleListInterface $moduleList,
        ProductMetadataInterface $productMetadata,
        StoreManagerInterface $storeManager,
        ScopeHelper $scopeHelper,
        ?EncryptorInterface $encryptor = null
    ) {
        $this->encryptor = $encryptor;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->moduleList = $moduleList;
        $this->productMetadata = $productMetadata;
        $this->storeManager = $storeManager;
        $this->scopeHelper = $scopeHelper;
    }

    /**
     * Get the base URL of the API.
     *
     * @return string
     */
    private function getApiUrl(): string
    {
        $testmode = $this->scopeHelper->getScopedConfigValue('payment/briqpay/test_mode');
        if ($testmode) {
            return 'https://playground-api.briqpay.com';
        }
        return 'https://api.briqpay.com';
    }

    /**
     * The client secret as configured: stored encrypted (Magento's "key:cipher:data"
     * form, decrypted here); any other value, such as one set in env.php, is used as it is.
     */
    private function secret(string $stored): string
    {
        if ($stored === '' || !preg_match('/^\\d+:\\d+:/', $stored)) {
            return $stored;
        }
        $this->encryptor = $this->encryptor ?? ObjectManager::getInstance()->get(EncryptorInterface::class);

        return (string) $this->encryptor->decrypt($stored);
    }

    private function getApiKey(): string
    {
        $clientId = $this->scopeHelper->getScopedConfigValue('payment/briqpay/client_id');
        $sharedSecret = $this->secret((string) $this->scopeHelper->getScopedConfigValue('payment/briqpay/shared_secret'));

        if (!$clientId || !$sharedSecret) {
            throw new \Exception('API credentials are not configured properly.');
        }

        return base64_encode($clientId . ':' . $sharedSecret);
    }

    /**
     * Set HTTP headers for API request.
     *
     * @throws \Exception
     */
    private function setHeaders(): void
    {
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->getApiKey());

        try {
            // Fetch Magento version
            $magentoVersion = $this->productMetadata->getVersion();

            // Fetch PHP version
            $phpVersion = phpversion();

            // Fetch Base URL
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();

            // Set user agent with module version, Magento version, PHP version, and base URL
            $moduleInfo = $this->moduleList->getOne('Briqpay_Payments');
            $moduleVersion = isset($moduleInfo['setup_version']) ? $moduleInfo['setup_version'] : 'Unknown';

            $userAgent = sprintf(
                'Briqpay M2 extension - version %s - Magento %s - PHP Version: %s - BaseUrl: %s',
                $moduleVersion,
                $magentoVersion,
                $phpVersion,
                $baseUrl
            );

            $this->curl->addHeader('User-Agent', $userAgent);
        } catch (\Exception $e) {
            $this->logger->error('Error setting HTTP headers: ' . $e->getMessage());
            throw new \Exception('Error setting HTTP headers: ' . $e->getMessage());
        }
    }

    /**
     * Make an API request.
     *
     * @param string $method HTTP method (GET, POST, PATCH, PUT, DELETE)
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @return array|null
     * @throws \Exception
     */
    public function request(string $method, string $endpoint, array $params = []): ?array
    {
        $url = $this->getApiUrl() . $endpoint;
        $this->setHeaders();
        // Magento's default is 300 seconds, which would hold a checkout or an admin
        // request that long when Briqpay does not answer.
        $this->curl->setTimeout(self::TIMEOUT);

        try {
            switch (strtoupper($method)) {
                case 'GET':
                    if (!empty($params)) {
                        $url .= '?' . http_build_query($params);
                    }
                    $this->curl->get($url);
                    break;
                case 'POST':
                    $this->curl->post($url, json_encode($params));
                    break;
                case 'PATCH':
                    $this->curl->patch($url, json_encode($params));
                    break;
                case 'PUT':
                    $this->curl->put($url, json_encode($params));
                    break;
                case 'DELETE':
                    $this->curl->delete($url);
                    break;
                default:
                    throw new \Exception('Invalid HTTP method.');
            }

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();
            $responseData = json_decode($response, true);
            if ($status >= 200 && $status < 300) {
                return $responseData;
            } else {
                if (isset($responseData['error']['code']) && $responseData['error']['code'] === 'CANCEL_NOT_SUPPORTED' &&
                    isset($responseData['error']['message']) && $responseData['error']['message'] === 'Cancel is not supported for the selected payment provider') {
                    return ['error' => self::CANCEL_NOT_SUPPORTED];
                }
                // The status and Briqpay's error code and message; the whole answer only
                // at debug level, as it can repeat what was sent.
                $this->logger->error(sprintf(
                    'Error in Briqpay API request: %d %s %s',
                    $status,
                    (string) ($responseData['error']['code'] ?? ''),
                    (string) ($responseData['error']['message'] ?? '')
                ));
                $this->logger->debug('Briqpay API error answer: ' . $response);
                throw new ApiRequestException("Error in API request: $response", (int) $status);
            }
        } catch (\Exception $e) {
            // What was sent can hold personal data: only at debug level.
            $this->logger->error('API request error', [
                'url' => $url,
                'method' => $method,
                'status' => $e instanceof ApiRequestException ? $e->getStatusCode() : null,
                'reason' => $e instanceof ApiRequestException ? null : $e->getMessage(),
            ]);
            $this->logger->debug('API request error details: ' . $e->getMessage(), ['params' => $params]);
            throw new \Exception('API request error: ' . $e->getMessage(), 0, $e);
        }
    }
}
