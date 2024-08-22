<?php
namespace Briqpay\Payments\Model;

use Magento\Framework\Model\AbstractModel;

class CustomTable extends AbstractModel
{
    protected function _construct()
    {
        $this->_init('Briqpay\Payments\Model\ResourceModel\CustomTable');
    }
}
