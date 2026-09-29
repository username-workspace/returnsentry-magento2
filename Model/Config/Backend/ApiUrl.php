<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class ApiUrl extends Value
{
    private const ORIGIN = '#^(https?)://(\[[0-9a-f:.]+\]|[^/?\#:@\[\]]+)(:\d+)?(?=[/?\#]|$)#i';
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $url = trim((string) $this->getValue());
        if ($url !== '') {
            if (preg_match(self::ORIGIN, $url, $match) !== 1
                || (strtolower($match[1]) === 'http' && !in_array(strtolower($match[2]), self::LOCAL_HOSTS, true))
            ) {
                throw new LocalizedException(__('The ReturnSentry URL must start with https://.'));
            }
            $url = $match[0];
        }
        $this->setValue($url);
        parent::beforeSave();
        return $this;
    }
}
