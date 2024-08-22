<?php

namespace Briqpay\Payments\Rest;

use Magento\Framework\HTTP\Client\Curl;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;

class ApiClient
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var LoggerInterface
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

    /**
     * ApiClient constructor.
     *
     * @param Curl $curl
     * @param Logger $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param ModuleListInterface $moduleList
     * @param ProductMetadataInterface $productMetadata
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Curl $curl,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        ModuleListInterface $moduleList,
        ProductMetadataInterface $productMetadata,
        StoreManagerInterface $storeManager
    ) {
        $this->curl = $curl;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->moduleList = $moduleList;
        $this->productMetadata = $productMetadata;
        $this->storeManager = $storeManager;
    }

    /**
     * Get the base URL of the API.
     *
     * @return string
     */
    private function getApiUrl(): string
    {
        $testmode = $this->scopeConfig->getValue('payment/briqpay/test_mode');
        if ($testmode) {
            return 'https://playground-api.briqpay.com';
        }
        return 'https://api.briqpay.com';
    }

    /**
     * Get the API key for authentication.
     *
     * @return string
     * @throws \Exception
     */
    private function getApiKey(): string
    {
        $clientId = $this->scopeConfig->getValue('payment/briqpay/client_id');
        $sharedSecret = $this->scopeConfig->getValue('payment/briqpay/shared_secret');

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
                    return ['error' => 'Order not canceled not at PSP'];
                }
                $this->logger->error("Error in Briqpay API request: $response, Status Code: $status");
                throw new \Exception("Error in API request: $response");
            }
        } catch (\Exception $e) {
            $this->logger->error('API request error: ' . $e->getMessage(), [
                'url' => $url,
                'method' => $method,
                'params' => $params,
            ]);
            throw new \Exception('API request error: ' . $e->getMessage());
        }
    }
}
