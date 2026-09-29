<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Console\Command;

use Magento\Config\Console\Command\EmulatedAdminhtmlAreaProcessor;
use Magento\Framework\Exception\LocalizedException;
use ReturnSentry\Connector\Model\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ApplyProfile extends Command
{
    public function __construct(
        private readonly EmulatedAdminhtmlAreaProcessor $adminArea,
        private readonly Settings $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('returnsentry:profile')
            ->setDescription('Apply a merchant profile (JSON file) to the ReturnSentry settings')
            ->addArgument('file', InputArgument::REQUIRED, 'Profile file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->adminArea->process(function () use ($input, $output): void {
                $entries = $this->settings->profile((string) $input->getArgument('file'));
                $locked = $this->settings->apply($entries);
                $output->writeln(sprintf('Profile applied: %s.', implode(', ', array_keys($entries))));
                if ($locked !== []) {
                    $output->writeln(sprintf(
                        '<comment>Locked in app/etc/env.php or config.php, left unchanged: %s.</comment>',
                        implode(', ', $locked)
                    ));
                }
            });
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        return Command::SUCCESS;
    }
}
