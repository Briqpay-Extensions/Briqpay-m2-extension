<?php

namespace Briqpay\Payments\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CheckoutType implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'consumer', 'label' => __('Consumer')],
            ['value' => 'business', 'label' => __('Business')],
        ];
    }
}
