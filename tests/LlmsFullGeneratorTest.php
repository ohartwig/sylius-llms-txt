<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use Koh\SyliusLlmsTxt\Generator\LlmsFullGenerator;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;

final class LlmsFullGeneratorTest extends TestCase
{
    public function testProductsFollowTheShortDocument(): void
    {
        $body = $this->generate($this->products(3));

        self::assertStringContainsString('# Tabak Budde', $body);
        self::assertStringContainsString("---\n\n## Products", $body);
        self::assertStringContainsString('### Produkt 1', $body);
        self::assertStringContainsString('https://shop.example.com/de_DE/products/produkt-1', $body);
    }

    public function testShopWithoutProductsFallsBackToTheShortDocument(): void
    {
        $body = $this->generate([]);

        self::assertStringNotContainsString('## Products', $body);
        self::assertStringContainsString('# Tabak Budde', $body);
    }

    /** Reaching the product cap must be stated, not silently hidden. */
    public function testHittingTheProductLimitIsDeclared(): void
    {
        $body = $this->generate($this->products(5), maxProducts: 5);

        self::assertStringContainsString('Showing the 5 most recent products', $body);
        self::assertStringContainsString('sitemap', $body);
    }

    public function testStayingUnderTheLimitSaysNothing(): void
    {
        self::assertStringNotContainsString('most recent products', $this->generate($this->products(2), maxProducts: 5));
    }

    /**
     * The byte budget stops between entries. A product cut in half reads as a
     * complete one with missing facts, which is worse than an absent one.
     */
    public function testByteBudgetTruncatesBetweenEntriesAndSaysSo(): void
    {
        $body = $this->generate($this->products(50), maxProducts: 50, maxBytes: 600);

        self::assertStringContainsString('Truncated at the size limit', $body);
        self::assertLessThan(1200, \strlen($body));

        // Whatever survived must be whole entries.
        preg_match_all('/^### (.+)$/m', $body, $matches);
        foreach ($matches[1] as $name) {
            self::assertStringContainsString(
                'products/'.strtolower(str_replace(' ', '-', $name)),
                $body,
                'every rendered product must still carry its URL',
            );
        }
    }

    public function testDescriptionsAreStrippedOfMarkupAndCapped(): void
    {
        $body = $this->generate([$this->product(1, '<p>Ein <strong>sehr</strong> '.str_repeat('langer ', 200).'Text</p>')]);

        self::assertStringNotContainsString('<strong>', $body);
        self::assertStringContainsString('…', $body);
    }

    /**
     * Not every shop fills in the short description. Falling back to the long
     * one is the difference between a product entry an agent can reason about
     * and a bare heading with a URL under it.
     */
    public function testTheLongDescriptionIsUsedWhenThereIsNoShortOne(): void
    {
        $body = $this->generate([$this->productWithDescriptions(null, '<p>Die lange Beschreibung.</p>')]);

        self::assertStringContainsString('Die lange Beschreibung.', $body);
        self::assertStringNotContainsString('<p>', $body);
    }

    /** A short description of nothing but whitespace is not a description. */
    public function testAWhitespaceOnlyShortDescriptionFallsBackToTheLongOne(): void
    {
        $body = $this->generate([$this->productWithDescriptions("  \n ", 'Die lange Beschreibung.')]);

        self::assertStringContainsString('Die lange Beschreibung.', $body);
    }

    public function testAProductWithNoDescriptionAtAllRendersHeadingAndUrlOnly(): void
    {
        $body = $this->generate([$this->productWithDescriptions(null, null)]);

        self::assertStringContainsString('### Produkt 1', $body);
        self::assertStringContainsString('https://shop.example.com/de_DE/products/produkt-1', $body);
        self::assertSame(
            "### Produkt 1\n\nhttps://shop.example.com/de_DE/products/produkt-1",
            trim(substr($body, (int) strpos($body, '### Produkt 1'))),
        );
    }

    /**
     * A product with no slug has no page. Rendering the URL anyway produces
     * `/de_DE/products/` — the catalogue index — under a specific product's
     * heading, and an agent following it reports back on the wrong page.
     */
    public function testAProductWithoutASlugGetsNoUrl(): void
    {
        $body = $this->generate([$this->productWithDescriptions(null, null, slug: null)]);

        self::assertStringContainsString('### Produkt 1', $body);
        self::assertStringNotContainsString('/de_DE/products/', $body);
    }

    public function testAProductWithAnEmptySlugGetsNoUrl(): void
    {
        $body = $this->generate([$this->productWithDescriptions(null, null, slug: '')]);

        self::assertStringNotContainsString('/de_DE/products/', $body);
    }

    /** Without a translated name the code is all an agent has to go on. */
    public function testAnUntranslatedProductFallsBackToItsCode(): void
    {
        $body = $this->generate([$this->productWithDescriptions(null, null, name: null)]);

        self::assertStringContainsString('### CODE-1', $body);
    }

    private function productWithDescriptions(
        ?string $short,
        ?string $long,
        ?string $slug = 'produkt-1',
        ?string $name = 'Produkt 1',
    ): ProductInterface {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn($name);
        $translation->method('getSlug')->willReturn($slug);
        $translation->method('getShortDescription')->willReturn($short);
        $translation->method('getDescription')->willReturn($long);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getCode')->willReturn('CODE-1');

        return $product;
    }

    /** @param list<ProductInterface> $products */
    private function generate(array $products, int $maxProducts = 200, int $maxBytes = 1_048_576): string
    {
        $short = new LlmsTxtGenerator(new LlmsTxtSectionRegistry([]), new AgentEndpointRegistry([]));

        return (new LlmsFullGenerator($short, $this->repository($products), $maxProducts, $maxBytes))
            ->generate($this->context());
    }

    /**
     * @param list<ProductInterface> $products
     *
     * @return ProductRepositoryInterface<ProductInterface>
     */
    private function repository(array $products): ProductRepositoryInterface
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findLatestByChannel')->willReturn($products);

        return $repository;
    }

    /** @return list<ProductInterface> */
    private function products(int $count): array
    {
        $products = [];
        for ($i = 1; $i <= $count; ++$i) {
            $products[] = $this->product($i, 'Beschreibung '.$i);
        }

        return $products;
    }

    private function product(int $number, string $description): ProductInterface
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('Produkt '.$number);
        $translation->method('getSlug')->willReturn('produkt-'.$number);
        $translation->method('getShortDescription')->willReturn($description);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getCode')->willReturn('CODE-'.$number);

        return $product;
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
