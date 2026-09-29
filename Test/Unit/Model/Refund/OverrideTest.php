<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Refund;

use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Refund\Override;

class OverrideTest extends TestCase
{
    public function testNoOverrideWithoutTheCheckbox(): void
    {
        $this->assertNull($this->override(['do_offline' => '1'], true)->requested());
        $this->assertNull($this->override(null, true)->requested());
    }

    public function testTheOverrideNeedsThePermission(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('You are not allowed to refund despite ReturnSentry.');

        $this->override(['returnsentry_override' => '1', 'returnsentry_override_reason' => 'Parcel found'], false)
            ->requested();
    }

    public function testTheOverrideNeedsAReasonOfThreeCharactersAtLeast(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Give a reason to refund despite ReturnSentry.');

        $override = $this->override(['returnsentry_override' => '1', 'returnsentry_override_reason' => ' ok '], true);
        $override->requested();
    }

    public function testTheOverrideNamesTheAdministratorAndTheReason(): void
    {
        $override = $this->override(
            ['returnsentry_override' => '1', 'returnsentry_override_reason' => ' Parcel found '],
            true
        );

        $this->assertSame(['decidedBy' => 'jane', 'reason' => 'Parcel found'], $override->requested());
    }

    /**
     * @param array<string, string>|null $creditmemo
     */
    private function override(?array $creditmemo, bool $allowed): Override
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('creditmemo')->willReturn($creditmemo);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with(Override::ACL_RESOURCE)->willReturn($allowed);
        $user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $user->setData('username', 'jane');
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);
        return new Override($request, $authorization, $auth);
    }
}
