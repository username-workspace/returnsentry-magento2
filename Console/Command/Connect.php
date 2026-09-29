<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Console\Command;

use Magento\Config\Console\Command\EmulatedAdminhtmlAreaProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\ConnectionTester;
use ReturnSentry\Connector\Model\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Connect extends Command
{
    private const ENVIRONMENT = [
        'url' => 'RETURNSENTRY_URL',
        'secret-key' => 'RETURNSENTRY_SECRET_KEY',
        'publishable-key' => 'RETURNSENTRY_PUBLISHABLE_KEY',
        'salt' => 'RETURNSENTRY_SALT',
    ];

    public function __construct(
        private readonly EmulatedAdminhtmlAreaProcessor $adminArea,
        private readonly Settings $settings,
        private readonly ConnectionTester $tester,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('returnsentry:connect')
            ->setDescription('Save the ReturnSentry address and keys, test them, then enable the module')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'https:// address, or RETURNSENTRY_URL')
            ->addOption('secret-key', null, InputOption::VALUE_REQUIRED, 'rs_sk_ key, or RETURNSENTRY_SECRET_KEY')
            ->addOption(
                'publishable-key',
                null,
                InputOption::VALUE_REQUIRED,
                'rs_pk_ key, or RETURNSENTRY_PUBLISHABLE_KEY'
            )
            ->addOption(
                'salt',
                null,
                InputOption::VALUE_REQUIRED,
                'Pseudonymization secret, 32 characters or more, or RETURNSENTRY_SALT; turns pseudonymization on'
            )
            ->addOption(
                'force-salt',
                null,
                InputOption::VALUE_NONE,
                'Replace a different pseudonymization secret already saved'
            )
            ->addOption('website', null, InputOption::VALUE_REQUIRED, 'Website code, default scope when omitted')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Merchant profile to apply (JSON file)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $values = [
            'general/api_url' => $this->value($input, 'url'),
            'general/secret_key' => $this->value($input, 'secret-key'),
            'general/publishable_key' => $this->value($input, 'publishable-key'),
        ];
        $salt = $this->value($input, 'salt');
        $problem = match (true) {
            $values['general/api_url'] === '' => 'Pass the ReturnSentry address with --url or RETURNSENTRY_URL.',
            !str_starts_with($values['general/secret_key'], 'rs_sk_') => 'The secret key must start with rs_sk_.',
            !str_starts_with($values['general/publishable_key'], 'rs_pk_')
                => 'The publishable key must start with rs_pk_.',
            $salt !== '' && strlen($salt) < Config::MIN_SALT_LENGTH
                => 'The pseudonymization secret must have at least 32 characters.',
            in_array($input->getOption('website'), ['', '0'], true)
                => 'Pass a website code with --website, or leave it out for the default scope.',
            default => null,
        };
        if ($problem !== null) {
            $output->writeln('<error>' . $problem . '</error>');
            return Command::INVALID;
        }
        if ($salt !== '') {
            $values += ['privacy/salt' => $salt, 'privacy/pseudonymize' => '1'];
        }
        try {
            return (int) $this->adminArea->process(fn (): int => $this->connect($input, $output, $values));
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
    }

    /**
     * @param array<string, string> $values
     * @throws LocalizedException
     * @throws \Exception
     */
    private function connect(InputInterface $input, OutputInterface $output, array $values): int
    {
        $website = $input->getOption('website');
        $website = is_string($website) ? $website : null;
        $scope = $website === null ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_WEBSITES;
        $storeId = $website === null ? null : $this->defaultStoreId($website);
        $profile = $input->getOption('profile');
        $entries = is_string($profile) ? $this->settings->profile($profile) : [];
        $live = $this->config->isConnected($storeId);
        $currentSalt = (string) $this->config->pseudonymSalt($storeId);
        $replacesSalt = $currentSalt !== '' && ($values['privacy/salt'] ?? $currentSalt) !== $currentSalt;
        if ($replacesSalt && $input->getOption('force-salt') !== true) {
            $output->writeln('<error>A different pseudonymization secret is already saved: replacing it separates'
                . ' pseudonymized customers from their history. Pass --force-salt to replace it anyway.</error>');
            return Command::INVALID;
        }

        $locked = $this->settings->save($live ? $values : $values + ['general/enabled' => '0'], $scope, $website);
        $output->writeln(sprintf(
            'Saved the address and keys%s at the %s scope.',
            isset($values['privacy/salt']) ? ', with pseudonymization on,' : '',
            $website ?? $scope
        ));
        $locked = array_unique([...$locked, ...$this->settings->apply($entries)]);
        if ($entries !== []) {
            $output->writeln(sprintf('Profile applied: %s.', implode(', ', array_keys($entries))));
        }
        if ($locked !== []) {
            $output->writeln(sprintf(
                '<comment>Locked in app/etc/env.php or config.php, left unchanged: %s.</comment>',
                implode(', ', $locked)
            ));
        }

        $result = $this->tester->test($storeId);
        if (!$result['success']) {
            $output->writeln('<error>Connection test failed: ' . $result['message'] . '</error>');
            $output->writeln(
                ($live ? 'ReturnSentry stays enabled and fails open meanwhile.' : 'ReturnSentry stays disabled.')
                . ' Fix the address or the keys, then connect again.'
            );
            return Command::FAILURE;
        }
        $this->settings->save(['general/enabled' => '1'], $scope, $website);
        $output->writeln($result['message'] . ' ReturnSentry is enabled.');
        $output->writeln(sprintf(
            'Checkout decisions: %s. Refund authorization: %s.',
            $this->config->mode(Config::CHECKOUT_DECISIONS, $storeId),
            $this->config->mode(Config::REFUND_AUTHORIZATION, $storeId)
        ));
        $output->writeln([
            'Next steps:',
            '  1. Keep the returnsentry.sync consumer running: cron_consumers_runner in app/etc/env.php,'
                . ' or bin/magento queue:consumers:start returnsentry.sync under a process supervisor.',
            '  2. Send the history: bin/magento returnsentry:backfill --from <YYYY-MM-DD>.',
            '  3. Compare the observed decisions, then enforce store view by store view (README, section 3.5).',
        ]);
        return Command::SUCCESS;
    }

    private function defaultStoreId(string $website): int
    {
        $groupId = (string) $this->storeManager->getWebsite($website)->getDefaultGroupId();
        return (int) $this->storeManager->getGroup($groupId)->getDefaultStoreId();
    }

    private function value(InputInterface $input, string $option): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return trim((string) ($input->getOption($option) ?? getenv(self::ENVIRONMENT[$option])));
    }
}
