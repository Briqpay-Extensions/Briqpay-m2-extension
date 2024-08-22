<?php
namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;

class ReadSession
{
    /**
     * @var SetupConfig
     */
    private $setupConfig;

    /**
     * @var ApiClient
     */
    protected $apiClient;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ReadSession constructor.
     * 
     * @param SetupConfig      $setupConfig
     * @param ApiClient        $apiClient
     * @param LoggerInterface  $logger
     */
    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
    }

    /**
     * Retrieve session data from API.
     * 
     * @param string $sessionId
     * @return mixed
     * @throws \Exception
     */
    public function getSession(string $sessionId)
    {
        $config = $this->setupConfig->getSetupConfig();
        $uri = '/v3/session/' . $sessionId;

        try {
            $response = $this->apiClient->request('GET', $uri);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching session: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Error fetching session');
        }
    }
}
