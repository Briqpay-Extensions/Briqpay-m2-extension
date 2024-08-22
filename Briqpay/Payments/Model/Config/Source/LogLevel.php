<?php

namespace Briqpay\Payments\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class LogLevel implements ArrayInterface
{
    /**
     * Return an array of log levels
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => \Monolog\Logger::DEBUG, 'label' => __('DEBUG')],
            ['value' => \Monolog\Logger::INFO, 'label' => __('INFO')],
            ['value' => \Monolog\Logger::NOTICE, 'label' => __('NOTICE')],
            ['value' => \Monolog\Logger::WARNING, 'label' => __('WARNING')],
            ['value' => \Monolog\Logger::ERROR, 'label' => __('ERROR')],
            ['value' => \Monolog\Logger::CRITICAL, 'label' => __('CRITICAL')],
            ['value' => \Monolog\Logger::ALERT, 'label' => __('ALERT')],
            ['value' => \Monolog\Logger::EMERGENCY, 'label' => __('EMERGENCY')]
        ];
    }
}
