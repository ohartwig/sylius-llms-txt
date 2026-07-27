<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\LlmsTxtLink;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class LlmsTxtGeneratorTest extends TestCase
{
    /** llmstxt.org: H1 title, then a blockquote summary. */
    public function testDocumentOpensWithTitleAndSummary(): void
    {
        $body = $this->generate(summary: 'Cigars, pipe tobacco and accessories.');

        self::assertStringStartsWith("# Tabak Budde\n", $body);
        self::assertStringContainsString('> Cigars, pipe tobacco and accessories.', $body);
    }

    public function testSectionsAppearInPriorityOrder(): void
    {
        $body = $this->generate(sections: [
            $this->section('Legal', -100),
            $this->section('Categories', 100),
        ]);

        self::assertLessThan(strpos($body, '## Legal'), strpos($body, '## Categories'));
    }

    /**
     * The endpoint list comes from the same registry that serves the endpoints,
     * so the document cannot advertise something the shop does not have.
     */
    public function testAgentEndpointsAreListedFromTheRegistry(): void
    {
        $body = $this->generate(endpoints: [
            new AgentEndpoint('/.well-known/acp.json', 'ACP discovery', description: 'Agentic Commerce Protocol.'),
        ]);

        self::assertStringContainsString('## Agent endpoints', $body);
        self::assertStringContainsString(
            '- [ACP discovery](https://shop.example.com/.well-known/acp.json): Agentic Commerce Protocol.',
            $body,
        );
    }

    public function testEndpointSectionIsOmittedWhenThereAreNone(): void
    {
        self::assertStringNotContainsString('## Agent endpoints', $this->generate());
    }

    public function testEndpointSectionCanBeTurnedOff(): void
    {
        $body = $this->generate(
            endpoints: [new AgentEndpoint('/.well-known/acp.json', 'ACP discovery')],
            listEndpoints: false,
        );

        self::assertStringNotContainsString('## Agent endpoints', $body);
    }

    public function testLinksRenderAsMarkdownListItems(): void
    {
        $body = $this->generate(sections: [$this->section('Categories', 100)]);

        self::assertStringContainsString('- [Zigarren](https://shop.example.com/de_DE/taxons/zigarren): Handrolled.', $body);
    }

    public function testDocumentEndsWithASingleNewline(): void
    {
        $body = $this->generate();

        self::assertSame("\n", substr($body, -1));
        self::assertNotSame("\n\n", substr($body, -2));
    }

    /**
     * Cache keys carry the channel, so one channel cannot be served another's
     * document — the classic multi-channel bug.
     */
    public function testStoreGeneratesOncePerKeyAndKeepsChannelsApart(): void
    {
        $store = new LlmsTxtStore(new ArrayAdapter());
        $calls = 0;
        $make = static function () use (&$calls): string {
            ++$calls;

            return 'body';
        };

        $store->get('koh_llms_txt.WEB_DE.de_DE', $make);
        $store->get('koh_llms_txt.WEB_DE.de_DE', $make);
        self::assertSame(1, $calls, 'a second read must come from the cache');

        $store->get('koh_llms_txt.WEB_AT.de_AT', $make);
        self::assertSame(2, $calls, 'a different channel must generate its own document');
    }

    public function testFindReturnsNullForAnUnwrittenKey(): void
    {
        self::assertNull((new LlmsTxtStore(new ArrayAdapter()))->find('koh_llms_full.WEB_DE.de_DE'));
    }

    /**
     * @param list<LlmsTxtSectionProviderInterface> $sections
     * @param list<AgentEndpoint>                   $endpoints
     */
    private function generate(
        array $sections = [],
        array $endpoints = [],
        ?string $summary = null,
        bool $listEndpoints = true,
    ): string {
        $generator = new LlmsTxtGenerator(
            new LlmsTxtSectionRegistry($sections),
            new AgentEndpointRegistry([$this->endpointProvider($endpoints)]),
            $summary,
            null,
            $listEndpoints,
        );

        return $generator->generate($this->context());
    }

    private function section(string $title, int $priority): LlmsTxtSectionProviderInterface
    {
        return new class($title, $priority) implements LlmsTxtSectionProviderInterface {
            public function __construct(private string $title, private int $priority)
            {
            }

            public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection
            {
                return new LlmsTxtSection(
                    $this->title,
                    [new LlmsTxtLink('Zigarren', $context->url('/de_DE/taxons/zigarren'), 'Handrolled.')],
                    $this->priority,
                );
            }
        };
    }

    /** @param list<AgentEndpoint> $endpoints */
    private function endpointProvider(array $endpoints): AgentEndpointProviderInterface
    {
        return new class($endpoints) implements AgentEndpointProviderInterface {
            /** @param list<AgentEndpoint> $endpoints */
            public function __construct(private array $endpoints)
            {
            }

            public function getAgentEndpoints(AgentContext $context): iterable
            {
                yield from $this->endpoints;
            }
        };
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
