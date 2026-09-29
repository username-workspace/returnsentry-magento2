<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class PublishableKey extends Value
{
    public const SECRET_PREFIX = 'rs_sk_';

    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        if (str_starts_with(trim((string) $this->getValue()), self::SECRET_PREFIX)) {
            throw new LocalizedException(
                __('This is a secret key: it would be published on every page. Paste the publishable key (rs_pk_...).')
            );
        }
        parent::beforeSave();
        return $this;
    }
}
