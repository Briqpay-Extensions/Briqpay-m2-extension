<?php
namespace Briqpay\Payments\Model\ResourceModel\CustomTable;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Briqpay\Payments\Model\CustomTable as Model;
use Briqpay\Payments\Model\ResourceModel\CustomTable as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
