<?php
namespace Briqpay\Payments\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;

class YesNoColumn extends Select
{
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function setInputId($value)
    {
        return $this->setId($value);
    }

    /**
     * Overriding calcOptionAttributes to inject the missing JS variable
     */
    public function calcOptionAttributes($option)
    {
        return 'value="' . $option['value'] . '"' . (isset($option['option_extra_attrs']) ? ' ' . $option['option_extra_attrs'] : ' option_extra_attrs=""');
    }

    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->addOption('0', __('No'));
            $this->addOption('1', __('Yes'));
        }

        $options = $this->getOptions();
        $html = '<select name="' . $this->getName() . '" id="' . $this->getId() . '" class="' . $this->getClass() . '" title="' . $this->getTitle() . '">';
        
        foreach ($options as $option) {
            // We force the option_extra_attrs attribute here string-wise
            $html .= '<option value="' . $option['value'] . '" option_extra_attrs="">' . $option['label'] . '</option>';
        }
        
        $html .= '</select>';
        return $html;
    }
}