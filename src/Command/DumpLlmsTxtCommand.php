<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Command;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\Generator\LlmsFullGenerator;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds llms.txt and llms-full.txt for every enabled channel.
 *
 * Meant for a cron or a post-deploy hook. llms-full.txt exists *only* through
 * this command: it walks the catalogue, which is not work to do inside a
 * request an agent controls.
 */
#[AsCommand(
    name: 'koh:agent:llms:dump',
    description: 'Generate llms.txt and llms-full.txt for every enabled channel.',
)]
final class DumpLlmsTxtCommand extends Command
{
    /** @param ChannelRepositoryInterface<ChannelInterface> $channelRepository */
    public function __construct(
        private readonly LlmsTxtGenerator $generator,
        private readonly LlmsFullGenerator $fullGenerator,
        private readonly LlmsTxtStore $store,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly ChannelRepositoryInterface $channelRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Limit to one channel code')
            ->addOption('print', 'p', InputOption::VALUE_NONE, 'Print instead of storing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = $input->getOption('channel');
        $channels = [];
        if (\is_string($code) && '' !== $code) {
            $channel = $this->channelRepository->findOneByCode($code);
            if (!$channel instanceof ChannelInterface) {
                $io->error(\sprintf('No channel with code "%s".', $code));

                return Command::FAILURE;
            }
            $channels[] = $channel;
        } else {
            foreach ($this->channelRepository->findEnabled() as $channel) {
                if ($channel instanceof ChannelInterface) {
                    $channels[] = $channel;
                }
            }
        }

        foreach ($channels as $channel) {
            $context = $this->contextFactory->create($channel);
            $short = $this->generator->generate($context);
            $full = $this->fullGenerator->generate($context);

            if ($input->getOption('print')) {
                $output->writeln($short);

                continue;
            }

            $this->store->put($context->cacheKey('koh_llms_txt'), $short);
            $this->store->put($context->cacheKey('koh_llms_full'), $full, 86400);

            $io->writeln(\sprintf(
                '  %s (%s): llms.txt %d B, llms-full.txt %d B',
                (string) $channel->getCode(),
                $context->localeCode,
                \strlen($short),
                \strlen($full),
            ));
        }

        $io->success(\sprintf('Generated for %d channel(s).', \count($channels)));

        return Command::SUCCESS;
    }
}
