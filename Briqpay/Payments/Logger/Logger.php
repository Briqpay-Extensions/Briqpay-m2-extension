<?php

namespace Briqpay\Payments\Logger;

use Monolog\Logger as MonologLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Store\Model\ScopeInterface;

class Logger extends MonologLogger
{
    protected $scopeConfig;
    protected $logLevel;
    protected $scopeHelper;
    /**
     * Customer data never written to the log, in test mode or live. Matched by key at any depth.
     *
     * @var array<int, string>
     */
    private $sensitiveKeys = [
        'firstName', 'lastName', 'email', 'streetAddress', 'streetAddress2', 'phoneNumber', 'zip', 'city',
        'payerPhone', 'identificationNumber', 'dateOfBirth', 'clientToken', 'htmlSnippet',
        // Briqpay's strong authentication, company and consumer blocks as a whole (a
        // sole trader's company number is a personal number); a cart line's name stays.
        'strongAuth', 'company', 'consumer', 'cin', 'companyName',
        // Magento's own address and customer keys, should a quote or address be logged.
        'firstname', 'lastname', 'telephone', 'street', 'postcode', 'customer_email',
    ];
    public function __construct(
        $name,
        ScopeConfigInterface $scopeConfig,
        ScopeHelper $scopeHelper,
        array $handlers = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->scopeHelper = $scopeHelper;
        parent::__construct($name, $handlers);
        $this->logLevel = $this->getLogLevel();
    }

    protected function getTestMode()
    {
        return $this->scopeHelper->getScopedConfigValue('payment/briqpay/test_mode');
    }

    /**
     * Retrieve log level from configuration
     *
     * @return int
     */
    protected function getLogLevel()
    {
        $logLevel = $this->scopeHelper->getScopedConfigValue(
            'payment/briqpay/advanced/log_level',
            ScopeInterface::SCOPE_STORE
        );
        // INFO unless configured: what the module did, without the request bodies DEBUG adds.
        return $logLevel ? (int)$logLevel : MonologLogger::INFO;
    }
    /**
     * Log a message if it meets or exceeds the configured log level
     *
     * @param int $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        if ($level >= $this->logLevel) {
            parent::log($level, $this->maskMessage((string) $message), $this->maskSensitiveData($context));
        }
    }

    public function info($message, array $context = []) : void
    {
        
        if (MonologLogger::INFO >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);
            parent::log(MonologLogger::INFO, $this->maskMessage((string) $message), $context);
        }
    }
    public function debug($message, array $context = []) : void
    {
        if (MonologLogger::DEBUG >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);

            
            parent::log(MonologLogger::DEBUG, $this->maskMessage((string) $message), $context);
        }
    }
    public function notice($message, array $context = []) : void
    {
        // log() filters by the configured level and masks.
        $this->log(MonologLogger::NOTICE, $message, $context);
    }
    public function warning($message, array $context = []) : void
    {
        if (MonologLogger::WARNING >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);
            parent::log(MonologLogger::WARNING, $this->maskMessage((string) $message), $context);
        }
    }
    public function error($message, array $context = []) : void
    {
        if (MonologLogger::ERROR >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);
            parent::log(MonologLogger::ERROR, $this->maskMessage((string) $message), $context);
        }
    }
    public function alert($message, array $context = []) : void
    {
        if (MonologLogger::ALERT >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);
            parent::log(MonologLogger::ALERT, $this->maskMessage((string) $message), $context);
        }
    }
    public function critical($message, array $context = []) : void
    {
        if (MonologLogger::CRITICAL >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);
            parent::log(MonologLogger::CRITICAL, $this->maskMessage((string) $message), $context);
        }
    }
    public function emergency($message, array $context = []) : void
    {
        if (MonologLogger::EMERGENCY >= $this->logLevel) {
            $context = $this->maskSensitiveData($context);
            parent::log(MonologLogger::EMERGENCY, $this->maskMessage((string) $message), $context);
        }
    }

    /**
     * An e-mail address in a log message is masked; the message is otherwise untouched.
     */
    private function maskMessage(string $message): string
    {
        return (string) preg_replace('/[\\w.+-]+@[\\w-]+(\\.[\\w-]+)+/', '***@***', $message);
    }

    private function maskSensitiveData(array $context) : array
    {
        foreach ($context as $key => &$value) {
            if (in_array($key, $this->sensitiveKeys, true)) {
                // Mask the value for sensitive keys
                $value = $this->maskValue($value);
            } elseif (is_array($value)) {
                // Recursively check nested arrays
                $value = $this->maskSensitiveData($value);
            }
        }

        return $context;
    }

    private function maskValue($value) : string
    {
        // Same length as the value, so an empty field stays recognisable as empty.
        return is_scalar($value) ? str_repeat('*', strlen((string) $value)) : '***';
    }
}
