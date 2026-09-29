<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Mapper\Identity;

class IdentityTest extends TestCase
{
    private const SALT = '0123456789abcdef0123456789abcdef-test';

    public function testPseudonymsMatchTheHistoryExportFormula(): void
    {
        $identity = $this->identity(self::SALT);

        $this->assertSame(
            'a244609f95ed4e72424a7c5984142bc8cd256b8b@pseudo.invalid',
            $identity->pseudonymousEmail('  Jane.Doe@Example.com', self::SALT)
        );
        $this->assertSame(
            '9E7C88203CCD61CC6E3DDAB37F9C148D90946CE58795F775C4A954D758C8AD1E',
            $identity->addressFingerprint('fr', '75 002', "12, Rue de la Paix\nBât. B", self::SALT)
        );
        $this->assertSame(
            'F79C907CFC9F5456F93D8009C735C4CDE1C3F2E4DD21DA03E15737960BACEA90',
            $identity->phoneFingerprint('+33 (0)6 12 34 56 78', self::SALT)
        );
        $this->assertNull($identity->phoneFingerprint('12 34', self::SALT));
        $this->assertNull($identity->addressFingerprint('FR', '75002', ' ', self::SALT));
    }

    public function testClearModeSendsTheContactData(): void
    {
        $identity = $this->identity(null);

        $this->assertSame(
            ['externalId' => 'm2:12', 'email' => 'jane@example.com', 'phone' => '+33612345678'],
            $identity->customer(12, ' jane@example.com ', '+33612345678', 1)
        );
        $this->assertSame(['externalId' => 'm2:12'], $identity->customer(12, null, '123', 1));
        $this->assertSame(
            ['shippingAddress' => [
                'line1' => '12 rue de la Paix',
                'line2' => 'Bât. B',
                'postalCode' => '75002',
                'city' => 'Paris',
                'country' => 'FR',
            ]],
            $identity->address($this->address(), 1)
        );
    }

    public function testPseudonymizedModeSendsTokensAndFingerprintsOnly(): void
    {
        $identity = $this->identity(self::SALT);

        $customer = $identity->customer(null, 'jane@example.com', '+33612345678', 1);
        $address = $identity->address($this->address(), 1);

        $this->assertSame(['email'], array_keys($customer));
        $this->assertStringEndsWith('@pseudo.invalid', $customer['email']);
        $this->assertSame(['country' => 'FR'], $address['shippingAddress']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $address['addressFingerprint']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $address['phoneFingerprint']);
    }

    public function testAnAddressWithoutCountryIsLeftOut(): void
    {
        $this->assertSame([], $this->identity(null)->address(new DataObject(['city' => 'Paris']), 1));
        $this->assertSame([], $this->identity(null)->address(null, 1));
    }

    private function address(): DataObject
    {
        return new DataObject([
            'country_id' => 'fr',
            'street' => "12 rue de la Paix\nBât. B",
            'postcode' => '75002',
            'city' => 'Paris',
            'telephone' => '+33612345678',
        ]);
    }

    private function identity(?string $salt): Identity
    {
        $config = $this->createMock(Config::class);
        $config->method('pseudonymSalt')->willReturn($salt);
        return new Identity($config);
    }
}
