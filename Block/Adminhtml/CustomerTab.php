<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Ui\Component\Layout\Tabs\TabInterface;

class CustomerTab extends Template implements TabInterface
{
    public function getTabLabel(): string
    {
        return (string) __('ReturnSentry');
    }

    public function getTabTitle(): string
    {
        return (string) __('ReturnSentry');
    }

    public function canShowTab(): bool
    {
        return (bool) $this->getRequest()->getParam('id');
    }

    public function isHidden(): bool
    {
        return !$this->canShowTab();
    }

    public function getTabClass(): string
    {
        return '';
    }

    public function getTabUrl(): string
    {
        return '';
    }

    public function isAjaxLoaded(): bool
    {
        return false;
    }
}
