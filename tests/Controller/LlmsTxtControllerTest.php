<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use Koh\SyliusLlmsTxt\Controller\LlmsTxtController;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The controller is where the two documents differ in kind: llms.txt is
 * generated on demand behind a cache, llms-full.txt is only ever read from the
 * store because building it walks the whole catalogue. Getting that backwards
 * hands an agent a way to make the shop do expensive work on request, so the
 * asymmetry is worth pinning down.
 */
final class LlmsTxtControllerTest extends TestCase
{
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testIndexServesTheGeneratedDocumentAsPlainText(): void
    {
        $response = $this->controller()->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('# Tabak Budde', (string) $response->getContent());
    }

    /**
     * Discovery documents are read cross-origin by in-browser agents and must
     * never be sniffed into something else.
     */
    public function testDiscoveryHeadersAreSet(): void
    {
        $response = $this->controller()->index(new Request());

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertNotNull($response->getEtag());
    }

    /** A returning crawler that sends If-None-Match must get a 304, not a rebuild. */
    public function testAConditionalRequestIsAnsweredWithNotModified(): void
    {
        $etag = (string) $this->controller()->index(new Request())->getEtag();

        $conditional = new Request();
        $conditional->headers->set('If-None-Match', $etag);

        $response = $this->controller()->index($conditional);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
    }

    /** The store is read first: the whole point of caching the short document. */
    public function testIndexServesTheCachedDocumentWithoutRegenerating(): void
    {
        $this->store()->put('koh_llms_txt.WEB_DE.de_DE', "# From the cache\n");

        $response = $this->controller()->index(new Request());

        self::assertSame("# From the cache\n", $response->getContent());
    }

    public function testIndexWarmsTheCacheOnAMiss(): void
    {
        $this->controller()->index(new Request());

        self::assertNotNull($this->store()->find('koh_llms_txt.WEB_DE.de_DE'));
    }

    /**
     * Cache keys carry the channel code, so one channel can never be served
     * another's document — the classic multi-channel bug.
     */
    public function testEachChannelGetsItsOwnCacheEntry(): void
    {
        $this->controller()->index(new Request());
        $this->controller(channelCode: 'WEB_AT', channelName: 'Tabak Wien', localeCode: 'de_AT')->index(new Request());

        self::assertStringContainsString('Tabak Budde', (string) $this->store()->find('koh_llms_txt.WEB_DE.de_DE'));
        self::assertStringContainsString('Tabak Wien', (string) $this->store()->find('koh_llms_txt.WEB_AT.de_AT'));
    }

    public function testFullServesTheDumpedDocumentAsMarkdown(): void
    {
        $this->store()->put('koh_llms_full.WEB_DE.de_DE', "# Tabak Budde\n\n## Products\n");

        $response = $this->controller()->full(new Request());

        self::assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('## Products', (string) $response->getContent());
    }

    /**
     * The documented reason there is no 404 here: llms.txt links to
     * llms-full.txt, and until the dump command has run that link would dangle.
     * An agent asking for the expanded form still gets something usable.
     */
    public function testFullFallsBackToTheShortDocumentWhenNothingWasDumped(): void
    {
        $response = $this->controller()->full(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('# Tabak Budde', (string) $response->getContent());
        self::assertStringNotContainsString('## Products', (string) $response->getContent());
    }

    /**
     * The expanded document is never built inside a request. Falling back must
     * reach for the short document's cache key, not generate a full one.
     */
    public function testTheFallbackDoesNotPopulateTheFullDocumentKey(): void
    {
        $this->controller()->full(new Request());

        self::assertNull($this->store()->find('koh_llms_full.WEB_DE.de_DE'));
        self::assertNotNull($this->store()->find('koh_llms_txt.WEB_DE.de_DE'));
    }

    /** A dumped full document belongs to one channel only. */
    public function testFullDoesNotServeAnotherChannelsDump(): void
    {
        $this->store()->put('koh_llms_full.WEB_AT.de_AT', "# Tabak Wien\n\n## Products\n");

        $response = $this->controller()->full(new Request());

        self::assertStringNotContainsString('Tabak Wien', (string) $response->getContent());
        self::assertStringContainsString('Tabak Budde', (string) $response->getContent());
    }

    private function store(): LlmsTxtStore
    {
        return new LlmsTxtStore($this->cache);
    }

    private function controller(
        string $channelCode = 'WEB_DE',
        string $channelName = 'Tabak Budde',
        string $localeCode = 'de_DE',
    ): LlmsTxtController {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($channelCode);
        $channel->method('getName')->willReturn($channelName);

        $context = new AgentContext($channel, 'https://shop.example.com', $localeCode, [$localeCode]);

        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($context);

        return new LlmsTxtController(
            new LlmsTxtGenerator(new LlmsTxtSectionRegistry([]), new AgentEndpointRegistry([])),
            $this->store(),
            $factory,
            new AgentJsonResponder(),
        );
    }
}
