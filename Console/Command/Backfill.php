<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Console\Command;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\Backfill as BackfillService;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Backfill extends Command
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly BackfillService $backfill
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('returnsentry:backfill')
            ->setDescription('Send past customers, orders, shipments, returns and credit memos to ReturnSentry')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day, included (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day, excluded (YYYY-MM-DD), default tomorrow')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code, default every connected one')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Map and count the records without sending them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $this->day($input->getOption('from'));
        $to = $this->day($input->getOption('to') ?? gmdate('Y-m-d', time() + 86400));
        if ($from === null || $to === null || $from >= $to) {
            $output->writeln('<error>--from is required; --from and --to are YYYY-MM-DD, --from first.</error>');
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $code = $input->getOption('store');
        $status = Command::SUCCESS;
        foreach ($this->storeManager->getStores() as $store) {
            $selected = $code === null || $store->getCode() === $code;
            if ($selected && ($dryRun || $this->config->isConnected((int) $store->getId()))) {
                $status = max($status, $this->backfillStore($store, $from, $to, $dryRun, $output));
            }
        }
        return $status;
    }

    private function backfillStore(
        StoreInterface $store,
        string $from,
        string $to,
        bool $dryRun,
        OutputInterface $output
    ): int {
        $label = $store->getCode();
        $output->writeln(sprintf('%s: %s to %s%s', $label, $from, $to, $dryRun ? ' (dry run)' : ''));
        $report = static fn (array $stats) => $output->writeln(sprintf(
            '  %s: %d orders, %d records, %d created, %d updated, %d rejected',
            $label,
            $stats['orders'],
            $stats['records'],
            $stats['created'],
            $stats['updated'],
            count($stats['errors'])
        ));
        try {
            $stats = $this->backfill->run((int) $store->getId(), $from, $to, $dryRun, $report);
        } catch (ApiException $exception) {
            $output->writeln(sprintf('<error>%s: %s</error>', $label, $exception->getMessage()));
            $output->writeln('<error>Records are idempotent: run the same window again.</error>');
            return Command::FAILURE;
        }
        foreach ($stats['errors'] as $error) {
            $output->writeln('  <comment>rejected: ' . $error . '</comment>');
        }
        return Command::SUCCESS;
    }

    private function day(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }
}
