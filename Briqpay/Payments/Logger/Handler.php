<?php

namespace Briqpay\Payments\Logger;

use Monolog\Handler\StreamHandler;
use Magento\Framework\Logger\Handler\Base as BaseHandler;

class Handler extends BaseHandler
{
  //  protected $loggerType = Logger::INFO; // Log level can be adjusted
    protected $fileName = '/var/log/briqpay_payments.log'; // Log file path
}
