<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class TaxonomyMap extends AbstractFieldArray
{
    private ?TaxonomySelect $select = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly OptionSourceInterface $options,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn('shop', ['label' => __('Shop value'), 'class' => 'required-entry']);
        $this->addColumn('standard', ['label' => __('ReturnSentry value'), 'renderer' => $this->select()]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $row->setData('option_extra_attrs', [
            'option_' . $this->select()->calcOptionHash((string) $row->getData('standard')) => 'selected="selected"',
        ]);
    }

    /**
     * @throws LocalizedException
     */
    private function select(): TaxonomySelect
    {
        if ($this->select === null) {
            $select = $this->getLayout()->createBlock(
                TaxonomySelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            if (!$select instanceof TaxonomySelect) {
                throw new LocalizedException(__('The ReturnSentry value selector could not be created.'));
            }
            $this->select = $select->setOptions($this->options->toOptionArray());
        }
        return $this->select;
    }
}
