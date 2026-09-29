<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\System;

use Magento\Framework\FlagManager;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\StoreManagerInterface;

class RefundControlAlert implements MessageInterface
{
    private const FLAG = 'returnsentry_refund_control';

    public function __construct(
        private readonly FlagManager $flags,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function raise(int $storeId, int $status): void
    {
        $refused = $this->refused();
        $refused[$this->websiteId($storeId)] = $status;
        $this->flags->saveFlag(self::FLAG, $refused);
    }

    public function clear(?int $storeId): void
    {
        $refused = $this->refused();
        if ($refused === []) {
            return;
        }
        if ($storeId !== null) {
            unset($refused[$this->websiteId($storeId)]);
        }
        if ($storeId === null || $refused === []) {
            $this->flags->deleteFlag(self::FLAG);
            return;
        }
        $this->flags->saveFlag(self::FLAG, $refused);
    }

    public function getIdentity(): string
    {
        return 'returnsentry_refund_control';
    }

    public function isDisplayed(): bool
    {
        return $this->refused() !== [];
    }

    public function getText(): string
    {
        $websites = array_map(
            fn (int $websiteId) => (string) $this->storeManager->getWebsite($websiteId)->getName(),
            array_keys($this->refused())
        );
        return (string) __(
            'ReturnSentry refuses the secret key or the URL of %1: refund control is inactive. '
            . 'Check them with the connection test.',
            implode(', ', $websites)
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * @return array<int, int>
     */
    private function refused(): array
    {
        $data = $this->flags->getFlagData(self::FLAG);
        return is_array($data) ? array_map('intval', $data) : [];
    }

    private function websiteId(int $storeId): int
    {
        return (int) $this->storeManager->getStore($storeId)->getWebsiteId();
    }
}
