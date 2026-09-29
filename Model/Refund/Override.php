<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Refund;

use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class Override
{
    public const ACL_RESOURCE = 'ReturnSentry_Connector::refund_override';
    private const MIN_REASON_LENGTH = 3;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly AuthorizationInterface $authorization,
        private readonly Auth $auth
    ) {
    }

    /**
     * @return array{decidedBy: string, reason: string}|null
     * @throws LocalizedException
     */
    public function requested(): ?array
    {
        $creditmemo = $this->request->getParam('creditmemo');
        if (!is_array($creditmemo) || empty($creditmemo['returnsentry_override'])) {
            return null;
        }
        if (!$this->authorization->isAllowed(self::ACL_RESOURCE)) {
            throw new LocalizedException(__('You are not allowed to refund despite ReturnSentry.'));
        }
        $reason = mb_substr(trim((string) ($creditmemo['returnsentry_override_reason'] ?? '')), 0, 500);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new LocalizedException(__('Give a reason to refund despite ReturnSentry.'));
        }
        $user = $this->auth->getUser();
        $name = $user instanceof DataObject ? (string) $user->getData('username') : '';
        return ['decidedBy' => mb_substr($name !== '' ? $name : 'admin', 0, 120), 'reason' => $reason];
    }
}
