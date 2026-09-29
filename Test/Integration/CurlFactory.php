<?php

declare(strict_types=1);

namespace Magento\Framework\HTTP\Client;

class CurlFactory
{
    public function create(): Curl
    {
        return new Curl();
    }
}
