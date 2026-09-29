<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class ConsentCookie extends Value
{
    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $expected = $this->getFieldsetDataValue('consent_cookie_value');
        if (trim((string) $this->getValue()) !== '' && is_string($expected) && trim($expected) === '') {
            throw new LocalizedException(
                __('Enter the consent cookie value that means consent, such as returnsentry or C0004:1.')
            );
        }
        parent::beforeSave();
        return $this;
    }
}
