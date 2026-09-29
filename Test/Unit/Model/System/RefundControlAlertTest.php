<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\System;

use Magento\Framework\FlagManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\System\RefundControlAlert;

class RefundControlAlertTest extends TestCase
{
    private mixed $flag = null;

    public function testARefusedKeyStaysOnScreenUntilACallSucceedsOnThatWebsite(): void
    {
        $alert = $this->alert();

        $alert->raise(1, 401);
        $alert->raise(3, 404);
        $shown = $alert->isDisplayed();
        $text = $alert->getText();
        $alert->clear(2);
        $stillShown = $alert->isDisplayed();
        $alert->clear(3);

        $this->assertTrue($shown);
        $this->assertStringContainsString('Main Website, Outlet', $text);
        $this->assertStringContainsString('refund control is inactive', $text);
        $this->assertTrue($stillShown);
        $this->assertFalse($alert->isDisplayed());
    }

    public function testASuccessAtDefaultScopeClearsEveryWebsite(): void
    {
        $alert = $this->alert();
        $alert->raise(1, 403);
        $alert->raise(3, 401);

        $alert->clear(null);

        $this->assertFalse($alert->isDisplayed());
    }

    private function alert(): RefundControlAlert
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function (string $code, mixed $value) {
            $this->flag = json_decode((string) json_encode($value), true);
            return true;
        });
        $flags->method('deleteFlag')->willReturnCallback(function () {
            $this->flag = null;
            return true;
        });
        $websites = [1 => 'Main Website', 2 => 'Outlet'];
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function (int $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getWebsiteId')->willReturn($storeId === 3 ? 2 : 1);
            return $store;
        });
        $storeManager->method('getWebsite')->willReturnCallback(function (int $websiteId) use ($websites) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getName')->willReturn($websites[$websiteId]);
            return $website;
        });
        return new RefundControlAlert($flags, $storeManager);
    }
}
