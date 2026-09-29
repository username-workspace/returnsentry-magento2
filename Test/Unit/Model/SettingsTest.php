<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Config\Model\Config as AdminConfig;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Settings;
use ReturnSentry\Connector\Model\Source\DecisionMode;
use ReturnSentry\Connector\Model\Source\RefundCategory;
use ReturnSentry\Connector\Model\Source\ReturnReason;

class SettingsTest extends TestCase
{
    private const YES_NO = [['value' => 1, 'label' => 'Yes'], ['value' => 0, 'label' => 'No']];

    /**
     * @var array<int, array{scope: string, code: ?string, paths: array<string, mixed>}>
     */
    private array $saved = [];

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            unlink($this->file);
        }
    }

    public function testAProfileIsSavedScopeByScopeThroughTheAdminSaveModel(): void
    {
        $settings = $this->settings();
        $reasons = ['too_small' => ['shop' => 'Too small', 'standard' => 'size_fit']];

        $entries = $settings->profile($this->file([
            'default' => [
                'features/beacon' => '0',
                'features/checkout_decisions' => 'observe',
                'checkout/risky_payment_methods' => 'cashondelivery,checkmo',
                'mapping/return_reasons' => $reasons,
                'mapping/refund_code_field' => 'reason_code',
            ],
            'stores' => ['us_en' => ['general/enabled' => '0']],
        ]));
        $this->assertSame([], $this->saved);
        $settings->apply($entries);

        $this->assertSame(['default', 'stores/us_en'], array_keys($entries));
        $this->assertSame([
            ['scope' => 'default', 'code' => null, 'paths' => [
                'returnsentry/features/beacon' => '0',
                'returnsentry/features/checkout_decisions' => 'observe',
                'returnsentry/checkout/risky_payment_methods' => 'cashondelivery,checkmo',
                'returnsentry/mapping/return_reasons' => $reasons,
                'returnsentry/mapping/refund_code_field' => 'reason_code',
            ]],
            ['scope' => 'stores', 'code' => 'us_en', 'paths' => ['returnsentry/general/enabled' => '0']],
        ], $this->saved);
    }

    /**
     * @param array<string, mixed> $profile
     */
    #[DataProvider('refusedProfiles')]
    public function testARefusedProfileSavesNothing(array $profile, string $reason): void
    {
        try {
            $this->settings()->profile($this->file($profile));
            $this->fail('the profile was accepted');
        } catch (LocalizedException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
        $this->assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedProfiles(): array
    {
        $default = static fn (array $values): array => ['default' => $values];
        return [
            'the address' => [$default(['general/api_url' => 'https://rs.example.com']), 'returnsentry:connect'],
            'the secret key' => [$default(['general/secret_key' => 'rs_sk_x']), 'returnsentry:connect'],
            'the publishable key' => [$default(['general/publishable_key' => 'rs_pk_x']), 'returnsentry:connect'],
            'the salt' => [$default(['privacy/salt' => str_repeat('s', 32)]), 'returnsentry:connect'],
            'turning the module on' => [$default(['general/enabled' => '1']), 'only turn ReturnSentry off'],
            'an unknown path' => [$default(['features/unknown' => '1']), 'not a ReturnSentry setting'],
            'a website setting on a store view' => [
                ['stores' => ['fr_fr' => ['privacy/pseudonymize' => '1']]],
                'at the stores scope',
            ],
            'a mode outside the list' => [$default(['features/checkout_decisions' => 'enforced']), 'does not accept'],
            'an unknown payment method' => [
                $default(['checkout/risky_payment_methods' => 'cashondelivery,klarna']),
                'does not accept',
            ],
            'a number instead of a string' => [$default(['features/beacon' => 1]), 'does not accept'],
            'an unknown standard reason' => [
                $default(['mapping/return_reasons' => ['a' => ['shop' => 'Too small', 'standard' => 'size']]]),
                'does not accept',
            ],
            'a reason row without a shop value' => [
                $default(['mapping/return_reasons' => ['a' => ['shop' => ' ', 'standard' => 'size_fit']]]),
                'does not accept',
            ],
            'an unknown refund category' => [
                $default(['mapping/refund_codes' => ['a' => ['shop' => 'CTR001', 'standard' => 'lost']]]),
                'does not accept',
            ],
            'an unknown store view' => [['stores' => ['xx_xx' => ['features/beacon' => '0']]], 'xx_xx'],
            'an unknown section' => [['global' => ['features/beacon' => '0']], 'default, websites and stores'],
        ];
    }

    public function testAPathLockedInTheDeploymentConfigIsReported(): void
    {
        $settings = $this->settings();

        $locked = $settings->save(['general/api_url' => 'https://rs.example.com', 'general/enabled' => '0']);

        $this->assertSame(['general/api_url'], $locked);
        $this->assertSame([], $settings->save(['general/api_url' => 'https://rs.example.com'], 'websites', 'eu'));
    }

    public function testAFileThatIsNotJsonIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not valid JSON');
        $this->settings()->profile($this->file('{"default": '));
    }

    private function settings(): Settings
    {
        $factory = $this->createMock(ConfigFactory::class);
        $factory->method('create')->willReturnCallback(function (array $arguments): AdminConfig {
            $index = count($this->saved);
            $data = $arguments['data'];
            $this->saved[$index] = ['scope' => $data['scope'], 'code' => $data['scope_code'], 'paths' => []];
            $config = $this->createMock(AdminConfig::class);
            $config->method('setDataByPath')->willReturnCallback(function (string $path, mixed $value) use ($index) {
                $this->saved[$index]['paths'][$path] = $value;
            });
            return $config;
        });
        $fields = [
            'general/enabled' => $this->field('select', self::YES_NO),
            'general/api_url' => $this->field('text', []),
            'features/beacon' => $this->field('select', self::YES_NO),
            'features/checkout_decisions' => $this->field('select', (new DecisionMode())->toOptionArray()),
            'checkout/risky_payment_methods' => $this->field('multiselect', [
                'checkmo' => ['value' => 'checkmo', 'label' => 'Check'],
                'offline' => ['label' => 'Offline', 'value' => [['value' => 'cashondelivery', 'label' => 'Cash']]],
            ]),
            'privacy/pseudonymize' => $this->field('select', self::YES_NO, false),
            'mapping/return_reasons' => $this->field('', []),
            'mapping/refund_codes' => $this->field('', []),
            'mapping/refund_code_field' => $this->field('text', []),
        ];
        $structure = $this->createMock(Structure::class);
        $structure->method('getFieldPaths')->willReturn(array_combine(
            array_map(static fn (string $path) => 'returnsentry/' . $path, array_keys($fields)),
            array_map(static fn (string $path) => ['returnsentry/' . $path], array_keys($fields))
        ));
        $structure->method('getElementByConfigPath')
            ->willReturnCallback(static fn (string $path) => $fields[substr($path, strlen('returnsentry/'))]);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(static function (string $code) {
            if ($code === 'xx_xx') {
                throw NoSuchEntityException::singleField('code', $code);
            }
            return null;
        });
        $checker = $this->createMock(SettingChecker::class);
        $checker->method('isReadOnly')->willReturnCallback(
            static fn (string $path, string $scope) => $path === 'returnsentry/general/api_url' && $scope === 'default'
        );
        return new Settings(
            $factory,
            $structure,
            $stores,
            new File(),
            new Json(),
            new ReturnReason(),
            new RefundCategory(),
            $checker
        );
    }

    /**
     * @param array<mixed> $options
     */
    private function field(string $type, array $options, bool $inStore = true): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('showInDefault')->willReturn(true);
        $field->method('showInWebsite')->willReturn(true);
        $field->method('showInStore')->willReturn($inStore);
        $field->method('getType')->willReturn($type);
        $field->method('hasOptions')->willReturn($options !== []);
        $field->method('getOptions')->willReturn($options);
        return $field;
    }

    /**
     * @param array<string, mixed>|string $profile
     */
    private function file(array|string $profile): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'rs-profile');
        file_put_contents($this->file, is_string($profile) ? $profile : json_encode($profile));
        return $this->file;
    }
}
