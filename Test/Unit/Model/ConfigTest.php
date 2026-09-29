<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Test\Unit\Support\MagentoConfig;

class ConfigTest extends TestCase
{
    use MagentoConfig;

    public function testDecisionModeIsOffUnlessConnectedAndValid(): void
    {
        $this->assertSame(Config::OBSERVE, $this->config([])->mode(Config::CHECKOUT_DECISIONS, 1));
        $this->assertSame(Config::ENFORCE, $this->config(['features/refund_authorization' => 'enforce'])
            ->mode(Config::REFUND_AUTHORIZATION, 1));
        $this->assertSame(Config::OFF, $this->config(['features/refund_authorization' => '1'])
            ->mode(Config::REFUND_AUTHORIZATION, 1));
        $this->assertSame(Config::OFF, $this->config(['general/enabled' => '0'])
            ->mode(Config::REFUND_AUTHORIZATION, 1));
    }

    public function testPseudonymizationWithoutAValidSecretDisconnectsTheModule(): void
    {
        $this->assertFalse($this->config(['privacy/pseudonymize' => '1'])->isConnected(1));
        $this->assertFalse($this->config(['privacy/pseudonymize' => '1', 'privacy/salt' => 'short'])->isConnected(1));
        $this->assertTrue($this->config(['privacy/pseudonymize' => '1', 'privacy/salt' => str_repeat('s', 32)])
            ->isConnected(1));
    }

    public function testGlobalEIsTheOnlyPaymentMethodExcludedByDefault(): void
    {
        $excluded = $this->config(['features/excluded_payment_methods' => 'globale,free']);
        $none = $this->config(['features/excluded_payment_methods' => '']);

        $this->assertTrue($excluded->isExcludedPaymentMethod('free', 1));
        $this->assertFalse($excluded->isExcludedPaymentMethod('checkmo', 1));
        $this->assertTrue($this->config([])->isExcludedPaymentMethod('globale', 1));
        $this->assertFalse($this->config([])->isExcludedPaymentMethod('checkmo', 1));
        $this->assertFalse($this->config([])->isExcludedPaymentMethod('', 1));
        $this->assertFalse($none->isExcludedPaymentMethod('globale', 1));
    }

    /**
     * @param array<string, string> $saved
     */
    private function config(array $saved): Config
    {
        $values = [
            'general/enabled' => '1',
            'general/api_url' => 'https://rs.example.com/',
            'general/secret_key' => 'rs_sk_test',
        ];
        foreach ($saved + $values as $path => $value) {
            $values['returnsentry/' . $path] = $value;
            unset($values[$path]);
        }
        return new Config($this->magentoScopeConfig($values), new Json());
    }
}
