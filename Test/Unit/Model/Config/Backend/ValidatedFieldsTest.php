<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config\Backend\ApiUrl;
use ReturnSentry\Connector\Model\Config\Backend\ConsentCookie;
use ReturnSentry\Connector\Model\Config\Backend\PublishableKey;
use ReturnSentry\Connector\Model\Config\Backend\Pseudonymize;

class ValidatedFieldsTest extends TestCase
{
    public function testTheUrlMustUseHttpsExceptOnThisMachine(): void
    {
        $this->assertSame('https://rs.example.com', $this->saved(ApiUrl::class, 'https://rs.example.com/'));
        $this->assertSame('http://127.0.0.1:4107', $this->saved(ApiUrl::class, 'http://127.0.0.1:4107'));
        $this->assertSame('http://[::1]:4107', $this->saved(ApiUrl::class, 'http://[::1]:4107/'));
        $this->expectException(LocalizedException::class);
        $this->saved(ApiUrl::class, 'http://rs.example.com');
    }

    public function testTheUrlKeepsOnlyTheOriginTheBeaconAndApiLiveAt(): void
    {
        $this->assertSame('https://rs.example.com', $this->saved(ApiUrl::class, 'https://rs.example.com/customers'));
        $this->assertSame('http://localhost', $this->saved(ApiUrl::class, 'http://localhost/rs?x=1'));
        $this->assertSame(
            'https://rs.example.com:8443',
            $this->saved(ApiUrl::class, 'https://rs.example.com:8443/login')
        );
        $this->expectException(LocalizedException::class);
        $this->saved(ApiUrl::class, 'https://user:secret@rs.example.com');
    }

    public function testASecretKeyIsRefusedAsPublishableKey(): void
    {
        $this->assertSame('rs_pk_live_1', $this->saved(PublishableKey::class, 'rs_pk_live_1'));
        $this->expectException(LocalizedException::class);
        $this->saved(PublishableKey::class, 'rs_sk_live_1');
    }

    public function testPseudonymizationNeedsASecretOfThirtyTwoCharacters(): void
    {
        $this->assertSame('0', $this->saved(Pseudonymize::class, '0'));
        $this->assertSame('1', $this->saved(Pseudonymize::class, '1', ['salt' => str_repeat('s', 32)]));
        $this->assertSame('1', $this->saved(Pseudonymize::class, '1', ['salt' => '******'], str_repeat('s', 40)));
        $this->expectException(LocalizedException::class);
        $this->saved(Pseudonymize::class, '1', ['salt' => 'too short']);
    }

    public function testPseudonymizationWithoutAnyStoredSecretIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Enter a pseudonymization secret of at least 32 characters');
        $this->saved(Pseudonymize::class, '1');
    }

    public function testAConsentCookieNeedsTheValueThatMeansConsent(): void
    {
        $this->assertSame('', $this->saved(ConsentCookie::class, ''));
        $this->assertSame('OptanonConsent', $this->saved(
            ConsentCookie::class,
            'OptanonConsent',
            ['consent_cookie_value' => 'C0004:1']
        ));
        $this->expectException(LocalizedException::class);
        $this->saved(ConsentCookie::class, 'OptanonConsent', ['consent_cookie_value' => '']);
    }

    public function testTheConsentCookieSavesBeforeItsValueOrWhileTheValueIsInherited(): void
    {
        $this->assertSame('OptanonConsent', $this->saved(ConsentCookie::class, 'OptanonConsent'));
        $this->assertSame('OptanonConsent', $this->saved(
            ConsentCookie::class,
            'OptanonConsent',
            ['consent_cookie_value' => null]
        ));
    }

    /**
     * @param class-string<Value> $class
     * @param array<string, string|null> $fieldset
     */
    private function saved(string $class, string $value, array $fieldset = [], string $storedSalt = ''): string
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn($storedSalt);
        $backend = new $class(
            $context,
            $this->createMock(Registry::class),
            $scope,
            $this->createMock(TypeListInterface::class)
        );
        $backend->setData(['value' => $value, 'fieldset_data' => $fieldset]);
        $backend->beforeSave();
        return (string) $backend->getValue();
    }
}
