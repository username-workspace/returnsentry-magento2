<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Console\Command;

use Magento\Config\Console\Command\EmulatedAdminhtmlAreaProcessor;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Console\Command\Connect;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\ConnectionTester;
use ReturnSentry\Connector\Model\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ConnectTest extends TestCase
{
    private const SECRET = 'rs_sk_secret';
    private const PUBLISHABLE = 'rs_pk_public';
    private const URL = 'https://rs.example.com';
    private const CONNECTION = [
        '--url' => self::URL,
        '--secret-key' => self::SECRET,
        '--publishable-key' => self::PUBLISHABLE,
    ];
    private const ENVIRONMENT = [
        'RETURNSENTRY_URL',
        'RETURNSENTRY_SECRET_KEY',
        'RETURNSENTRY_PUBLISHABLE_KEY',
        'RETURNSENTRY_SALT',
    ];

    private Settings&MockObject $settings;

    private ConnectionTester&MockObject $tester;

    private bool $live = false;

    private ?string $currentSalt = null;

    /**
     * @var string[]
     */
    private array $locked = [];

    /**
     * @var array<int, array{array<string, mixed>, string, ?string}>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('save')->willReturnCallback(function (array $values, string $scope, ?string $code) {
            $this->saved[] = [$values, $scope, $code];
            return array_values(array_intersect($this->locked, array_keys($values)));
        });
        $this->tester = $this->createMock(ConnectionTester::class);
    }

    protected function tearDown(): void
    {
        foreach (self::ENVIRONMENT as $name) {
            putenv($name);
        }
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('malformedConnections')]
    public function testAMalformedConnectionIsRefusedBeforeAnythingIsSaved(array $options, string $reason): void
    {
        $this->tester->expects($this->never())->method('test');

        $run = $this->connect($options + ['--url' => self::URL]);

        $this->assertSame(Command::INVALID, $run->getStatusCode());
        $this->assertStringContainsString($reason, $run->getDisplay());
        $this->assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function malformedConnections(): array
    {
        $keys = ['--secret-key' => self::SECRET, '--publishable-key' => self::PUBLISHABLE];
        return [
            'no address' => [['--url' => ''] + $keys, '--url or RETURNSENTRY_URL'],
            'no secret key' => [['--publishable-key' => self::PUBLISHABLE], 'must start with rs_sk_'],
            'a publishable key as secret key' => [
                ['--secret-key' => self::PUBLISHABLE, '--publishable-key' => self::PUBLISHABLE],
                'must start with rs_sk_',
            ],
            'a secret key as publishable key' => [
                ['--secret-key' => self::SECRET, '--publishable-key' => self::SECRET],
                'must start with rs_pk_',
            ],
            'a short salt' => [['--salt' => str_repeat('s', 31)] + $keys, 'at least 32 characters'],
            'an empty website' => [['--website' => ''] + $keys, 'Pass a website code with --website'],
            'the admin website' => [['--website' => '0'] + $keys, 'Pass a website code with --website'],
        ];
    }

    public function testAPassingTestEnablesTheModuleAfterSavingTheKeysDisabled(): void
    {
        $this->tester->expects($this->once())->method('test')->with(null)
            ->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $run = $this->connect(self::CONNECTION);

        $this->assertSame(Command::SUCCESS, $run->getStatusCode());
        $this->assertSame([
            [[
                'general/api_url' => self::URL,
                'general/secret_key' => self::SECRET,
                'general/publishable_key' => self::PUBLISHABLE,
                'general/enabled' => '0',
            ], 'default', null],
            [['general/enabled' => '1'], 'default', null],
        ], $this->saved);
        $display = $run->getDisplay();
        $this->assertStringContainsString('Connected to ReturnSentry. ReturnSentry is enabled.', $display);
        $this->assertStringContainsString('Checkout decisions: observe. Refund authorization: observe.', $display);
        $this->assertStringContainsString('queue:consumers:start returnsentry.sync', $display);
    }

    public function testAFailedTestLeavesTheModuleDisabledAndSaysWhy(): void
    {
        $this->tester->method('test')
            ->willReturn(['success' => false, 'message' => __('ReturnSentry refused the secret key.')]);

        $run = $this->connect(self::CONNECTION);

        $this->assertSame(Command::FAILURE, $run->getStatusCode());
        $this->assertCount(1, $this->saved);
        $this->assertSame('0', $this->saved[0][0]['general/enabled']);
        $display = $run->getDisplay();
        $this->assertStringContainsString('Connection test failed: ReturnSentry refused the secret key.', $display);
        $this->assertStringContainsString('ReturnSentry stays disabled.', $display);
    }

    public function testAFailedTestOnALiveShopSavesTheNewKeysAndLeavesItEnabled(): void
    {
        $this->live = true;
        $outage = __('ReturnSentry cannot be reached at this URL: %1', 'timeout');
        $this->tester->method('test')->willReturn(['success' => false, 'message' => $outage]);

        $run = $this->connect(self::CONNECTION);

        $this->assertSame(Command::FAILURE, $run->getStatusCode());
        $this->assertSame([[[
            'general/api_url' => self::URL,
            'general/secret_key' => self::SECRET,
            'general/publishable_key' => self::PUBLISHABLE,
        ], 'default', null]], $this->saved);
        $display = $run->getDisplay();
        $this->assertStringContainsString('Connection test failed: ReturnSentry cannot be reached', $display);
        $this->assertStringContainsString('ReturnSentry stays enabled and fails open meanwhile.', $display);
    }

    public function testAPassingTestOnALiveShopReplacesTheKeysWithoutTurningItOff(): void
    {
        $this->live = true;
        $this->tester->method('test')->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $run = $this->connect(self::CONNECTION);

        $this->assertSame(Command::SUCCESS, $run->getStatusCode());
        $this->assertSame([
            [[
                'general/api_url' => self::URL,
                'general/secret_key' => self::SECRET,
                'general/publishable_key' => self::PUBLISHABLE,
            ], 'default', null],
            [['general/enabled' => '1'], 'default', null],
        ], $this->saved);
        $this->assertStringContainsString('ReturnSentry is enabled.', $run->getDisplay());
    }

    public function testSecretsFromTheEnvironmentConnectAWebsiteWithPseudonymization(): void
    {
        $salt = str_repeat('s', 32);
        putenv('RETURNSENTRY_URL=' . self::URL);
        putenv('RETURNSENTRY_SECRET_KEY=' . self::SECRET);
        putenv('RETURNSENTRY_PUBLISHABLE_KEY=' . self::PUBLISHABLE);
        putenv('RETURNSENTRY_SALT=' . $salt);
        $this->tester->expects($this->once())->method('test')->with(3)
            ->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $run = $this->connect(['--website' => 'eu']);

        $this->assertSame(Command::SUCCESS, $run->getStatusCode());
        $this->assertSame([
            [[
                'general/api_url' => self::URL,
                'general/secret_key' => self::SECRET,
                'general/publishable_key' => self::PUBLISHABLE,
                'privacy/salt' => $salt,
                'privacy/pseudonymize' => '1',
                'general/enabled' => '0',
            ], 'websites', 'eu'],
            [['general/enabled' => '1'], 'websites', 'eu'],
        ], $this->saved);
        $this->assertStringNotContainsString(self::SECRET, $run->getDisplay());
    }

    public function testADifferentPseudonymizationSecretIsNotReplacedWithoutForceSalt(): void
    {
        $this->currentSalt = str_repeat('a', 32);
        $this->tester->expects($this->never())->method('test');

        $run = $this->connect(self::CONNECTION + ['--salt' => str_repeat('b', 32)]);

        $this->assertSame(Command::INVALID, $run->getStatusCode());
        $this->assertStringContainsString('Pass --force-salt to replace it anyway.', $run->getDisplay());
        $this->assertSame([], $this->saved);
    }

    public function testForceSaltReplacesTheSavedPseudonymizationSecret(): void
    {
        $this->currentSalt = str_repeat('a', 32);
        $this->tester->method('test')->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $run = $this->connect(self::CONNECTION + ['--salt' => str_repeat('b', 32), '--force-salt' => true]);

        $this->assertSame(Command::SUCCESS, $run->getStatusCode());
        $this->assertSame(str_repeat('b', 32), $this->saved[0][0]['privacy/salt']);
    }

    public function testTheSameSecretOrNoneKeepsThePseudonymizationSecret(): void
    {
        $this->currentSalt = str_repeat('a', 32);
        $this->tester->method('test')->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $same = $this->connect(self::CONNECTION + ['--salt' => str_repeat('a', 32)]);
        $none = $this->connect(self::CONNECTION);

        $this->assertSame(Command::SUCCESS, $same->getStatusCode());
        $this->assertSame(Command::SUCCESS, $none->getStatusCode());
        $this->assertArrayNotHasKey('privacy/salt', $this->saved[2][0]);
    }

    public function testPathsLockedInTheDeploymentConfigAreNamed(): void
    {
        $this->locked = ['general/api_url'];
        $this->settings->method('apply')->willReturn(['general/api_url', 'features/beacon']);
        $this->tester->method('test')->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $run = $this->connect(self::CONNECTION);

        $this->assertStringContainsString(
            'Locked in app/etc/env.php or config.php, left unchanged: general/api_url, features/beacon.',
            $run->getDisplay()
        );
    }

    public function testTheProfileIsCheckedBeforeTheKeysAreSavedAndAppliedBeforeTheTest(): void
    {
        $entries = ['default' => ['scope' => 'default', 'code' => null, 'values' => ['features/beacon' => '0']]];
        $this->settings->expects($this->once())->method('profile')->with('profile.json')->willReturn($entries);
        $this->settings->expects($this->once())->method('apply')->with($entries)
            ->willReturnCallback(function (): array {
                $this->assertCount(1, $this->saved);
                return [];
            });
        $this->tester->method('test')->willReturn(['success' => true, 'message' => __('Connected to ReturnSentry.')]);

        $run = $this->connect(self::CONNECTION + ['--profile' => 'profile.json']);

        $this->assertSame(Command::SUCCESS, $run->getStatusCode());
        $this->assertStringContainsString('Profile applied: default.', $run->getDisplay());
    }

    public function testARefusedProfileSavesNothing(): void
    {
        $this->settings->method('profile')
            ->willThrowException(new LocalizedException(__('%1 does not accept this value.', 'features/beacon')));
        $this->tester->expects($this->never())->method('test');

        $run = $this->connect(self::CONNECTION + ['--profile' => 'profile.json']);

        $this->assertSame(Command::INVALID, $run->getStatusCode());
        $this->assertStringContainsString('features/beacon does not accept this value.', $run->getDisplay());
        $this->assertSame([], $this->saved);
    }

    /**
     * @param array<string, string> $options
     */
    private function connect(array $options): CommandTester
    {
        $area = $this->createMock(EmulatedAdminhtmlAreaProcessor::class);
        $area->method('process')->willReturnCallback(static fn (callable $run) => $run());
        $config = $this->createMock(Config::class);
        $config->method('mode')->willReturn(Config::OBSERVE);
        $config->method('isConnected')->willReturn($this->live);
        $config->method('pseudonymSalt')->willReturn($this->currentSalt);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getDefaultGroupId')->willReturn(2);
        $group = $this->createMock(GroupInterface::class);
        $group->method('getDefaultStoreId')->willReturn(3);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getWebsite')->with('eu')->willReturn($website);
        $stores->method('getGroup')->with('2')->willReturn($group);
        $tester = new CommandTester(new Connect($area, $this->settings, $this->tester, $config, $stores));
        $tester->execute($options);
        return $tester;
    }
}
