<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusLlmsTxt\Agent\LlmsTxtEndpointProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * How an agent that has never heard of llms.txt finds it: the RFC 9727 catalogue
 * at /.well-known/api-catalog, projected both as a linkset document and as Link
 * response headers. A wrong href or a wrong media type here is the difference
 * between a discoverable shop and one that only answers if you already know the
 * path.
 */
final class LlmsTxtEndpointProviderTest extends TestCase
{
    public function testBothDocumentsAreAnnouncedAsChannelAbsoluteUrls(): void
    {
        $entry = $this->entry();

        self::assertSame('https://shop.example.com', $entry->anchor);
        self::assertSame(
            ['https://shop.example.com/llms.txt', 'https://shop.example.com/llms-full.txt'],
            array_map(static fn ($target): string => $target->href, $entry->describedBy),
        );
    }

    /**
     * llms.txt is plain text and llms-full.txt is Markdown. An agent picks the
     * document it can parse off the media type, so swapping them sends it to the
     * wrong one.
     */
    public function testTheTwoDocumentsCarryTheirOwnMediaTypes(): void
    {
        $entry = $this->entry();

        self::assertSame('text/plain', $entry->describedBy[0]->type);
        self::assertSame('text/markdown', $entry->describedBy[1]->type);
    }

    /**
     * describedby, not service-desc: llms.txt describes the shop for a reader,
     * it is not a machine-readable description of an API.
     */
    public function testTheEntryIsARelationOfTheShopItselfAndNotAnApiDescription(): void
    {
        $array = $this->entry()->toArray();

        self::assertArrayHasKey('describedby', $array);
        self::assertArrayNotHasKey('service-desc', $array);
        self::assertSame('Shop overview for language models', $array['title']);
    }

    /** The anchor and both hrefs come from the channel, never from a constant. */
    public function testEveryUrlFollowsTheChannelBaseUrl(): void
    {
        $entry = $this->entry('https://shop.example.at');

        self::assertSame('https://shop.example.at', $entry->anchor);
        self::assertSame('https://shop.example.at/llms.txt', $entry->describedBy[0]->href);
        self::assertSame('https://shop.example.at/llms-full.txt', $entry->describedBy[1]->href);
    }

    /**
     * llms.txt is where the endpoint list is rendered. Announcing it as an agent
     * endpoint too would have the document cite itself.
     */
    public function testTheProviderContributesExactlyOneCatalogueEntry(): void
    {
        $entries = iterator_to_array(
            (new LlmsTxtEndpointProvider())->getApiCatalogEntries($this->context()),
            false,
        );

        self::assertCount(1, $entries);
        self::assertInstanceOf(ApiCatalogEntry::class, $entries[0]);
    }

    private function entry(string $baseUrl = 'https://shop.example.com'): ApiCatalogEntry
    {
        $entries = iterator_to_array(
            (new LlmsTxtEndpointProvider())->getApiCatalogEntries($this->context($baseUrl)),
            false,
        );

        self::assertInstanceOf(ApiCatalogEntry::class, $entries[0]);

        return $entries[0];
    }

    private function context(string $baseUrl = 'https://shop.example.com'): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, $baseUrl, 'de_DE', ['de_DE']);
    }
}
