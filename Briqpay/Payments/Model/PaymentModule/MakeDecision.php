<?php
namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;

class MakeDecision
{
    /**
     * @var SetupConfig
     */
    protected $setupConfig;

    /**
     * @var ApiClient
     */
    protected $apiClient;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * MakeDecision constructor.
     *
     * @param SetupConfig $setupConfig
     * @param ApiClient $apiClient
     * @param Logger $logger
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
     * Makes a decision for a session.
     *
     * @param string $sessionId
     * @param bool $decision
     * @return array|null
     * @throws \Exception
     */
    public function makeDecision(string $sessionId, bool $decision, bool $softError): ?array
    {
        $config = $this->setupConfig->getSetupConfig();
        $uri = '/v3/session/' . $sessionId . '/decision';

        // Initialize the body array with 'decision' key
        $body = ['decision' => $decision ? 'allow' : 'reject'];

        // Append 'rejectionType' only if $decision is false
        if (!$decision) {
            if ($softError) {
                $body['rejectionType'] = 'notify_user';
                $body['softErrors'] = []; 
                $body['softErrors'][] = [
                    'message' => __('Something went wrong, try reloading the page. If you are still having problems please contact support.')
                ];
            } else {
                $body['rejectionType'] = 'notify_user';
            }
        }

        try {
            $response = $this->apiClient->request('POST', $uri, $body);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error making decision for session ' . $sessionId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Error making decision for session ' . $sessionId);
        }
    }
}
