<?php

namespace Briqpay\Payments\Logger;

use Monolog\Logger as MonologLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Logger extends MonologLogger
{
    protected $scopeConfig;
    protected $logLevel;
    private $sensitiveKeys = ['firstName', 'lastName', 'email', 'streetAddress','streetAddress2', 'phoneNumber'];
    private $testMode;
    public function __construct(
        $name,
        ScopeConfigInterface $scopeConfig,
        array $handlers = []
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($name, $handlers);
        $this->logLevel = $this->getLogLevel();
        $this->testMode  = $this->scopeConfig->getValue('payment/briqpay/test_mode');
    }

    /**
     * Retrieve log level from configuration
     *
     * @return int
     */
    protected function getLogLevel()
    {
        $logLevel = $this->scopeConfig->getValue(
            'payment/briqpay/log_level',
            ScopeInterface::SCOPE_STORE
        );
        return $logLevel ? (int)$logLevel : MonologLogger::DEBUG; // Default to DEBUG if not set
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
            parent::log($level, $message, $context);
        }
    }

    public function info($message, array $context = []) : void
    {
        
        if (MonologLogger::INFO >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }
            parent::log(MonologLogger::INFO, $message, $context);
        }
    }
    public function debug($message, array $context = []) : void
    {
        if (MonologLogger::DEBUG >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }

            
            parent::log(MonologLogger::DEBUG, $message, $context);
        }
    }
    public function warning($message, array $context = []) : void
    {
        if (MonologLogger::WARNING >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }
            parent::log(MonologLogger::WARNING, $message, $context);
        }
    }
    public function error($message, array $context = []) : void
    {
        if (MonologLogger::ERROR >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }
            parent::log(MonologLogger::ERROR, $message, $context);
        }
    }
    public function alert($message, array $context = []) : void
    {
        if (MonologLogger::ALERT >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }
            parent::log(MonologLogger::ALERT, $message, $context);
        }
    }
    public function critical($message, array $context = []) : void
    {
        if (MonologLogger::CRITICAL >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }
            parent::log(MonologLogger::CRITICAL, $message, $context);
        }
    }
    public function emergency($message, array $context = []) : void
    {
        if (MonologLogger::EMERGENCY >= $this->logLevel) {
            if (!$this->testMode) {
                $context = $this->maskSensitiveData($context);
            }
            parent::log(MonologLogger::EMERGENCY, $message, $context);
        }
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
        // You can customize the masking logic here
        return str_repeat('*', strlen($value));
    }
}
