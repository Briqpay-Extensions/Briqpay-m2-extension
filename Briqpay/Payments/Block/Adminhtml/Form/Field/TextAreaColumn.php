<?php
namespace Briqpay\Payments\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\AbstractBlock;

class TextAreaColumn extends AbstractBlock
{
    /**
     * Render the textarea html
     *
     * @return string
     */
    protected function _toHtml()
    {
        $columnName = $this->getInputName();
        $columnId = $this->getInputId();
        
        return '<textarea name="' . $columnName . '" id="' . $columnId . '" 
            class="admin__control-textarea" 
            style="width:100%; min-width:400px; height:80px;" 
            rows="4"></textarea>';
    }
}