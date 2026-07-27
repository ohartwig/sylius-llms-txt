<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\LlmsTxtLink;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;

/**
 * Lists the channel's category tree — the shop's actual shape.
 *
 * Depth is capped because the point is orientation. A four-level tree flattened
 * into a bullet list stops being a map and becomes the catalogue this document
 * is deliberately not.
 */
final class TaxonSectionProvider implements LlmsTxtSectionProviderInterface
{
    /** @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository */
    public function __construct(
        private readonly TaxonRepositoryInterface $taxonRepository,
        private readonly string $title = 'Categories',
        private readonly int $maxDepth = 2,
        private readonly int $limit = 200,
        private readonly int $priority = 100,
    ) {
    }

    public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection
    {
        $menuTaxon = $context->channel->getMenuTaxon();
        if (!$menuTaxon instanceof TaxonInterface) {
            return null;
        }

        $root = $menuTaxon->getRoot() ?? $menuTaxon;
        $rootCode = $root->getCode();
        if (null === $rootCode) {
            return null;
        }

        $links = [];
        foreach ($this->taxonRepository->findChildren($rootCode, $context->localeCode) as $taxon) {
            if (!$taxon instanceof TaxonInterface || !$taxon->isEnabled()) {
                continue;
            }

            // getLevel() counts from the root; the menu taxon itself is level 0.
            if ($taxon->getLevel() > $this->maxDepth) {
                continue;
            }

            $slug = $taxon->getTranslation($context->localeCode)->getSlug();
            if (null === $slug || '' === $slug) {
                continue;
            }

            $links[] = new LlmsTxtLink(
                title: $taxon->getTranslation($context->localeCode)->getName() ?? (string) $taxon->getCode(),
                url: $context->url('/'.$context->localeCode.'/taxons/'.$slug),
                description: $this->plain($taxon->getTranslation($context->localeCode)->getDescription()),
            );

            if (\count($links) >= $this->limit) {
                break;
            }
        }

        return [] === $links ? null : new LlmsTxtSection($this->title, $links, $this->priority);
    }

    private function plain(?string $text): ?string
    {
        if (null === $text || '' === trim($text)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 197).'…' : $text;
    }
}
