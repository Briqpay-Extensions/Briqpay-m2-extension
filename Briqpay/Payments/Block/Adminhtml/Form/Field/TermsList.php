<?php
namespace Briqpay\Payments\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

class TermsList extends AbstractFieldArray
{
    protected function _prepareToRender()
    {   
        // New Name/Key field
        $this->addColumn('name', [
            'label' => __('Key Name'),
            'class' => 'required-entry validate-code', 
            'style' => 'width:120px',
            'data-validate' => '{
                "validate-code": true,
                "no-whitespace": true
            }'
        ]);

        $this->addColumn('is_required', [
            'label' => __('Required'),
            'renderer' => $this->getYesNoRenderer(),
            'extra_params' => 'option_extra_attrs=""',
            'style' => 'width:80px'
        ]);

        // New Default State field
        $this->addColumn('is_default', [
            'label' => __('Checked by Default'),
            'renderer' => $this->getYesNoRenderer(),
            'extra_params' => 'option_extra_attrs=""',
            'style' => 'width:80px'
        ]);

        $this->addColumn('content', [
            'label' => __('Terms text'),
            'renderer' => $this->getTextAreaRenderer(),
            'class' => 'required-entry'
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add New Term Row');
    }

    private function getYesNoRenderer()
    {
        return $this->getLayout()->createBlock(
            \Briqpay\Payments\Block\Adminhtml\Form\Field\YesNoColumn::class,
            '',
            ['data' => ['is_render_to_js_template' => true]]
        );
    }

    private function getTextAreaRenderer()
    {
        return $this->getLayout()->createBlock(
            \Briqpay\Payments\Block\Adminhtml\Form\Field\TextAreaColumn::class,
            '',
            ['data' => ['is_render_to_js_template' => true]]
        );
    }
}