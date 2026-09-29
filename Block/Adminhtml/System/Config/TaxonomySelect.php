<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Block\Adminhtml\System\Config;

use Magento\Framework\View\Element\Html\Select;

class TaxonomySelect extends Select
{
    public function setInputName(string $name): self
    {
        return $this->setName($name);
    }
}
