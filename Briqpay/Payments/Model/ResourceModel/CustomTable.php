<?php
namespace Briqpay\Payments\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CustomTable extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('payment_capture_mapping', 'id');
    }
}
