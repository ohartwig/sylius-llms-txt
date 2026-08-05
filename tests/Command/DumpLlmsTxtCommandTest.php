<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests\Command;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use Koh\SyliusLlmsTxt\Command\DumpLlmsTxtCommand;
use Koh\SyliusLlmsTxt\Generator\LlmsFullGenerator;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use Koh\SyliusLlmsTxt\Tests\Double\RecordingCacheItemPool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * llms-full.txt exists only through this command — the expanded document is
 * never built inside a request. If the command silently writes nothing, or
 * writes one channel's document under another's key, the shop serves a stale or
 * wrong document and the route it is served from cannot tell.
 */
final class DumpLlmsTxtCommandTest extends TestCase
{
    private RecordingCacheItemPool $pool;

    protected function setUp(): void
    {
        $this->pool = new RecordingCacheItemPool();
    }

    public function testEveryEnabledChannelIsDumped(): void
    {
        $tester = $this->tester([$this->channel('WEB_DE', 'Tabak Budde'), $this->channel('WEB_AT', 'Tabak Wien')]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertArrayHasKey('koh_llms_txt.WEB_DE.de_DE', $this->pool->values);
        self::assertArrayHasKey('koh_llms_txt.WEB_AT.de_AT', $this->pool->values);
        self::assertStringContainsString('Generated for 2 channel(s).', $tester->getDisplay());
    }

    /** One channel's document must never be stored under another's key. */
    public function testEachChannelsDocumentIsStoredUnderItsOwnKey(): void
    {
        $this->tester([$this->channel('WEB_DE', 'Tabak Budde'), $this->channel('WEB_AT', 'Tabak Wien')])->execute([]);

        self::assertStringContainsString('# Tabak Budde', (string) $this->pool->values['koh_llms_txt.WEB_DE.de_DE']);
        self::assertStringContainsString('# Tabak Wien', (string) $this->pool->values['koh_llms_txt.WEB_AT.de_AT']);
    }

    public function testBothDocumentsAreWrittenPerChannel(): void
    {
        $this->tester([$this->channel('WEB_DE', 'Tabak Budde')], products: 2)->execute([]);

        self::assertStringNotContainsString('## Products', (string) $this->pool->values['koh_llms_txt.WEB_DE.de_DE']);
        self::assertStringContainsString('## Products', (string) $this->pool->values['koh_llms_full.WEB_DE.de_DE']);
    }

    /**
     * The expanded document gets a day, not the short document's lifetime: only
     * this command can rebuild it, so it has to outlive an ordinary cache
     * window or the route falls back to the short document between runs.
     */
    public function testTheExpandedDocumentIsStoredForADay(): void
    {
        $this->tester([$this->channel('WEB_DE', 'Tabak Budde')], products: 1)->execute([]);

        self::assertSame(86400, $this->pool->lifetimes['koh_llms_full.WEB_DE.de_DE']);
        self::assertSame(3600, $this->pool->lifetimes['koh_llms_txt.WEB_DE.de_DE']);
    }

    public function testOneChannelCanBeSelectedByCode(): void
    {
        $tester = $this->tester(
            [$this->channel('WEB_DE', 'Tabak Budde')],
            byCode: ['WEB_AT' => $this->channel('WEB_AT', 'Tabak Wien')],
        );

        self::assertSame(Command::SUCCESS, $tester->execute(['--channel' => 'WEB_AT']));

        self::assertArrayHasKey('koh_llms_txt.WEB_AT.de_AT', $this->pool->values);
        self::assertArrayNotHasKey('koh_llms_txt.WEB_DE.de_DE', $this->pool->values);
        self::assertStringContainsString('Generated for 1 channel(s).', $tester->getDisplay());
    }

    /**
     * A typo in the channel code must not quietly succeed having done nothing —
     * this runs from a deploy hook, and a zero exit code there means "the shop's
     * documents are current".
     */
    public function testAnUnknownChannelCodeFailsLoudly(): void
    {
        $tester = $this->tester([$this->channel('WEB_DE', 'Tabak Budde')]);

        self::assertSame(Command::FAILURE, $tester->execute(['--channel' => 'NOPE']));
        self::assertStringContainsString('No channel with code "NOPE"', $tester->getDisplay());
        self::assertSame([], $this->pool->values);
    }

    /** An empty --channel means "all channels", not "the channel with no code". */
    public function testAnEmptyChannelOptionFallsBackToEveryEnabledChannel(): void
    {
        $tester = $this->tester([$this->channel('WEB_DE', 'Tabak Budde')]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--channel' => '']));
        self::assertArrayHasKey('koh_llms_txt.WEB_DE.de_DE', $this->pool->values);
    }

    public function testPrintWritesTheDocumentAndStoresNothing(): void
    {
        $tester = $this->tester([$this->channel('WEB_DE', 'Tabak Budde')]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--print' => true]));
        self::assertStringContainsString('# Tabak Budde', $tester->getDisplay());
        self::assertSame([], $this->pool->values, 'printing must not touch the cache');
    }

    public function testStoringReportsTheSizeOfEachDocument(): void
    {
        $tester = $this->tester([$this->channel('WEB_DE', 'Tabak Budde')], products: 2);
        $tester->execute([]);

        self::assertMatchesRegularExpression(
            '/WEB_DE \(de_DE\): llms\.txt \d+ B, llms-full\.txt \d+ B/',
            $tester->getDisplay(),
        );
    }

    /** A repository may hand back anything; only real channels are dumped. */
    public function testNonChannelEntriesFromTheRepositoryAreIgnored(): void
    {
        $tester = $this->tester([new \stdClass(), $this->channel('WEB_DE', 'Tabak Budde')]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Generated for 1 channel(s).', $tester->getDisplay());
    }

    /**
     * @param list<mixed>                       $enabled
     * @param array<string, ChannelInterface>   $byCode
     */
    private function tester(array $enabled, array $byCode = [], int $products = 0): CommandTester
    {
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findEnabled')->willReturn($enabled);
        $channelRepository->method('findOneByCode')->willReturnCallback(
            static fn (string $code): ?ChannelInterface => $byCode[$code] ?? null,
        );

        $generator = new LlmsTxtGenerator(new LlmsTxtSectionRegistry([]), new AgentEndpointRegistry([]));

        $command = new DumpLlmsTxtCommand(
            $generator,
            new LlmsFullGenerator($generator, $this->productRepository($products)),
            new LlmsTxtStore($this->pool),
            $this->contextFactory(),
            $channelRepository,
        );

        return new CommandTester($command);
    }

    private function contextFactory(): AgentContextFactoryInterface
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturnCallback(
            static function (?ChannelInterface $channel): AgentContext {
                self::assertNotNull($channel, 'the dump command always names the channel it is building for');
                $locale = 'WEB_AT' === $channel->getCode() ? 'de_AT' : 'de_DE';

                return new AgentContext($channel, 'https://shop.example.com', $locale, [$locale]);
            },
        );

        return $factory;
    }

    /** @return ProductRepositoryInterface<ProductInterface> */
    private function productRepository(int $count): ProductRepositoryInterface
    {
        $products = [];
        for ($i = 1; $i <= $count; ++$i) {
            $translation = $this->createMock(ProductTranslationInterface::class);
            $translation->method('getName')->willReturn('Produkt '.$i);
            $translation->method('getSlug')->willReturn('produkt-'.$i);
            $translation->method('getShortDescription')->willReturn('Beschreibung '.$i);

            $product = $this->createMock(ProductInterface::class);
            $product->method('getTranslation')->willReturn($translation);
            $product->method('getCode')->willReturn('CODE-'.$i);

            $products[] = $product;
        }

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findLatestByChannel')->willReturn($products);

        return $repository;
    }

    private function channel(string $code, string $name): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getName')->willReturn($name);

        return $channel;
    }
}
