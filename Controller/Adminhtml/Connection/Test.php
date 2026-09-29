<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\ConnectionTester;

class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'ReturnSentry_Connector::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ConnectionTester $tester,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $storeId = (int) $this->getRequest()->getParam('store') ?: null;
        $websiteId = (int) $this->getRequest()->getParam('website');
        if ($storeId === null && $websiteId > 0) {
            $website = $this->storeManager->getWebsite($websiteId);
            $storeId = (int) $this->storeManager->getGroup((string) $website->getDefaultGroupId())->getDefaultStoreId();
        }
        $result = $this->tester->test($storeId);
        return $this->jsonFactory->create()->setData([
            'success' => $result['success'],
            'message' => (string) $result['message'],
        ]);
    }
}
