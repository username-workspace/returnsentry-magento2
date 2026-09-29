<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ConnectionTest extends Field
{
    protected $_template = 'ReturnSentry_Connector::config/connection-test.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    public function getTestUrl(): string
    {
        return $this->getUrl('returnsentry/connection/test', [
            'website' => (int) $this->getRequest()->getParam('website'),
            'store' => (int) $this->getRequest()->getParam('store'),
        ]);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }
}
