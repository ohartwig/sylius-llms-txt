<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;
use Koh\SyliusLlmsTxt\Provider\TaxonSectionProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;

/**
 * The category section is the one part of llms.txt that reads live catalogue
 * data, so every skip rule in it is a rule about what a language model is told
 * the shop sells. A taxon that leaks through disabled, or one that renders with
 * an empty slug, produces a link an agent will follow into a 404.
 */
final class TaxonSectionProviderTest extends TestCase
{
    public function testChildrenOfTheMenuTaxonBecomeLinks(): void
    {
        $section = $this->provide([
            $this->taxon('zigarren', 'Zigarren', level: 1),
            $this->taxon('pfeifen', 'Pfeifen', level: 1),
        ]);

        self::assertNotNull($section);
        $markdown = $section->toMarkdown();

        self::assertStringContainsString('## Categories', $markdown);
        self::assertStringContainsString('[Zigarren](https://shop.example.com/de_DE/taxons/zigarren)', $markdown);
        self::assertStringContainsString('[Pfeifen](https://shop.example.com/de_DE/taxons/pfeifen)', $markdown);
    }

    /**
     * A disabled taxon has no shop page. Listing it hands an agent a link into
     * a 404 and tells it the shop sells something it does not.
     */
    public function testDisabledTaxonsAreSkipped(): void
    {
        $section = $this->provide([
            $this->taxon('zigarren', 'Zigarren', level: 1),
            $this->taxon('geheim', 'Geheim', level: 1, enabled: false),
        ]);

        self::assertNotNull($section);
        self::assertStringNotContainsString('Geheim', $section->toMarkdown());
        self::assertStringContainsString('Zigarren', $section->toMarkdown());
    }

    /**
     * The depth cap is what keeps this a map instead of the catalogue the
     * document deliberately is not.
     */
    public function testTaxonsBelowTheDepthCapAreSkipped(): void
    {
        $section = $this->provide(
            [
                $this->taxon('zigarren', 'Zigarren', level: 1),
                $this->taxon('havanna', 'Havanna', level: 2),
                $this->taxon('cohiba', 'Cohiba', level: 3),
            ],
            maxDepth: 2,
        );

        self::assertNotNull($section);
        $markdown = $section->toMarkdown();

        self::assertStringContainsString('Zigarren', $markdown);
        self::assertStringContainsString('Havanna', $markdown);
        self::assertStringNotContainsString('Cohiba', $markdown);
    }

    public function testRaisingTheDepthCapAdmitsTheDeeperLevel(): void
    {
        $section = $this->provide(
            [$this->taxon('cohiba', 'Cohiba', level: 3)],
            maxDepth: 3,
        );

        self::assertNotNull($section);
        self::assertStringContainsString('Cohiba', $section->toMarkdown());
    }

    /** No slug means no URL to link to, so the entry cannot be rendered at all. */
    public function testTaxonsWithoutASlugAreSkipped(): void
    {
        $section = $this->provide([
            $this->taxon(null, 'Ohne Slug', level: 1),
            $this->taxon('', 'Leerer Slug', level: 1),
            $this->taxon('zigarren', 'Zigarren', level: 1),
        ]);

        self::assertNotNull($section);
        $markdown = $section->toMarkdown();

        self::assertStringNotContainsString('Ohne Slug', $markdown);
        self::assertStringNotContainsString('Leerer Slug', $markdown);
        self::assertStringContainsString('Zigarren', $markdown);
    }

    public function testTheLinkLimitCutsTheList(): void
    {
        $taxons = [];
        for ($i = 1; $i <= 10; ++$i) {
            $taxons[] = $this->taxon('taxon-'.$i, 'Taxon '.$i, level: 1);
        }

        $section = $this->provide($taxons, limit: 3);

        self::assertNotNull($section);
        self::assertSame(3, substr_count($section->toMarkdown(), "\n- ["));
    }

    public function testDescriptionsAreStrippedOfMarkupAndCappedAt200Characters(): void
    {
        $section = $this->provide([
            $this->taxon(
                'zigarren',
                'Zigarren',
                level: 1,
                description: '<p>Handrolled  <strong>cigars</strong>'."\n".str_repeat('and more ', 60).'</p>',
            ),
        ]);

        self::assertNotNull($section);
        $markdown = $section->toMarkdown();

        self::assertStringNotContainsString('<strong>', $markdown);
        self::assertStringContainsString('Handrolled cigars and more', $markdown);
        self::assertStringContainsString('…', $markdown);

        preg_match('/\): (.*)$/m', $markdown, $matches);
        self::assertSame(198, mb_strlen($matches[1]), 'the cap is 197 characters plus the ellipsis');
    }

    public function testShortDescriptionsSurviveWhole(): void
    {
        $section = $this->provide([
            $this->taxon('zigarren', 'Zigarren', level: 1, description: 'Handrolled.'),
        ]);

        self::assertNotNull($section);
        self::assertStringContainsString('): Handrolled.', $section->toMarkdown());
        self::assertStringNotContainsString('…', $section->toMarkdown());
    }

    /**
     * Absent, not empty. Markdown renders a null and an empty description
     * identically, so the distinction has to be asserted on the link itself —
     * every other consumer of the section (JSON, a template) tells them apart.
     */
    public function testAWhitespaceOnlyDescriptionBecomesNoDescription(): void
    {
        $section = $this->provide([
            $this->taxon('zigarren', 'Zigarren', level: 1, description: "  \n\t "),
        ]);

        self::assertNotNull($section);
        self::assertNull($section->links[0]->description);
        self::assertStringContainsString('[Zigarren](https://shop.example.com/de_DE/taxons/zigarren)', $section->toMarkdown());
        self::assertStringNotContainsString('):', $section->toMarkdown());
    }

    public function testATaxonWithNoDescriptionAtAllCarriesNone(): void
    {
        $section = $this->provide([$this->taxon('zigarren', 'Zigarren', level: 1)]);

        self::assertNotNull($section);
        self::assertNull($section->links[0]->description);
    }

    /** A taxon whose translation carries no name still has to be identifiable. */
    public function testAnUntranslatedTaxonFallsBackToItsCode(): void
    {
        $section = $this->provide([
            $this->taxon('zigarren', null, level: 1, code: 'ZIGARREN'),
        ]);

        self::assertNotNull($section);
        self::assertStringContainsString('[ZIGARREN](', $section->toMarkdown());
    }

    /** A channel with no menu taxon has no category tree to describe. */
    public function testNoMenuTaxonMeansNoSection(): void
    {
        $repository = $this->createMock(TaxonRepositoryInterface::class);
        $repository->expects(self::never())->method('findChildren');

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getMenuTaxon')->willReturn(null);

        $context = new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);

        self::assertNull((new TaxonSectionProvider($repository))->getLlmsTxtSection($context));
    }

    /**
     * findChildren() keys off the root code. Without one there is nothing to
     * query, and querying with an empty string would return the wrong tree.
     */
    public function testARootWithoutACodeMeansNoSection(): void
    {
        $repository = $this->createMock(TaxonRepositoryInterface::class);
        $repository->expects(self::never())->method('findChildren');

        $root = $this->createMock(TaxonInterface::class);
        $root->method('getCode')->willReturn(null);

        $menuTaxon = $this->createMock(TaxonInterface::class);
        $menuTaxon->method('getRoot')->willReturn($root);

        $provider = new TaxonSectionProvider($repository);

        self::assertNull($provider->getLlmsTxtSection($this->context(menuTaxon: $menuTaxon)));
    }

    /** A menu taxon that is itself the root reports no root of its own. */
    public function testAMenuTaxonThatIsItsOwnRootIsQueriedDirectly(): void
    {
        $menuTaxon = $this->createMock(TaxonInterface::class);
        $menuTaxon->method('getRoot')->willReturn(null);
        $menuTaxon->method('getCode')->willReturn('MENU');

        $repository = $this->createMock(TaxonRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findChildren')
            ->with('MENU', 'de_DE')
            ->willReturn([$this->taxon('zigarren', 'Zigarren', level: 1)]);

        $section = (new TaxonSectionProvider($repository))->getLlmsTxtSection($this->context(menuTaxon: $menuTaxon));

        self::assertNotNull($section);
        self::assertStringContainsString('Zigarren', $section->toMarkdown());
    }

    /** Nothing survived the filters: an empty "Categories" heading helps nobody. */
    public function testAllChildrenFilteredOutMeansNoSection(): void
    {
        self::assertNull($this->providerFor([$this->taxon('geheim', 'Geheim', level: 1, enabled: false)])
            ->getLlmsTxtSection($this->context()));
    }

    public function testTitleAndPriorityAreConfigurable(): void
    {
        $provider = new TaxonSectionProvider(
            $this->repository([$this->taxon('zigarren', 'Zigarren', level: 1)]),
            title: 'Sortiment',
            priority: 42,
        );

        $section = $provider->getLlmsTxtSection($this->context());

        self::assertNotNull($section);
        self::assertSame('Sortiment', $section->title);
        self::assertSame(42, $section->priority);
    }

    /** @param list<TaxonInterface> $taxons */
    private function provide(array $taxons, int $maxDepth = 2, int $limit = 200): ?LlmsTxtSection
    {
        return (new TaxonSectionProvider($this->repository($taxons), 'Categories', $maxDepth, $limit))
            ->getLlmsTxtSection($this->context());
    }

    /** @param list<TaxonInterface> $taxons */
    private function providerFor(array $taxons): TaxonSectionProvider
    {
        return new TaxonSectionProvider($this->repository($taxons));
    }

    /**
     * @param list<TaxonInterface> $taxons
     *
     * @return TaxonRepositoryInterface<TaxonInterface>
     */
    private function repository(array $taxons): TaxonRepositoryInterface
    {
        $repository = $this->createMock(TaxonRepositoryInterface::class);
        $repository->method('findChildren')->willReturn($taxons);

        return $repository;
    }

    private function taxon(
        ?string $slug,
        ?string $name,
        int $level,
        bool $enabled = true,
        ?string $description = null,
        string $code = 'CODE',
    ): TaxonInterface {
        $translation = $this->createMock(TaxonTranslationInterface::class);
        $translation->method('getSlug')->willReturn($slug);
        $translation->method('getName')->willReturn($name);
        $translation->method('getDescription')->willReturn($description);

        $taxon = $this->createMock(TaxonInterface::class);
        $taxon->method('isEnabled')->willReturn($enabled);
        $taxon->method('getLevel')->willReturn($level);
        $taxon->method('getCode')->willReturn($code);
        $taxon->method('getTranslation')->willReturn($translation);

        return $taxon;
    }

    private function context(?TaxonInterface $menuTaxon = null): AgentContext
    {
        if (null === $menuTaxon) {
            $root = $this->createMock(TaxonInterface::class);
            $root->method('getCode')->willReturn('MENU');

            $menuTaxon = $this->createMock(TaxonInterface::class);
            $menuTaxon->method('getRoot')->willReturn($root);
        }

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');
        $channel->method('getMenuTaxon')->willReturn($menuTaxon);

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
