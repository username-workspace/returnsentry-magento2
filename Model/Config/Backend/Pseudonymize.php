<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use ReturnSentry\Connector\Model\Config;

class Pseudonymize extends Value
{
    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        if ((int) $this->getValue() === 1 && strlen($this->salt()) < Config::MIN_SALT_LENGTH) {
            throw new LocalizedException(
                __('Enter a pseudonymization secret of at least 32 characters before pseudonymizing.')
            );
        }
        parent::beforeSave();
        return $this;
    }

    private function salt(): string
    {
        $posted = trim((string) $this->getFieldsetDataValue('salt'));
        if ($posted !== '' && preg_match('/^\*+$/', $posted) !== 1) {
            return $posted;
        }
        return trim((string) $this->_config->getValue(
            'returnsentry/privacy/salt',
            $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $this->getScopeCode()
        ));
    }
}
