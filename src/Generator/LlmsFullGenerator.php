<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Generator;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\MarkdownText;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;

/**
 * Builds /llms-full.txt: the short document plus product detail.
 *
 * Bounded twice over, because this is the document that grows without limit. A
 * shop that quietly emits 40 MB gets truncated at some proxy along the way, and
 * an agent then reads a document that stops mid-sentence — worse than one that
 * says less on purpose and says so.
 */
final class LlmsFullGenerator
{
    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly LlmsTxtGenerator $generator,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly int $maxProducts = 200,
        private readonly int $maxBytes = 1_048_576,
    ) {
    }

    public function generate(AgentContext $context): string
    {
        $products = $this->productRepository->findLatestByChannel(
            $context->channel,
            $context->localeCode,
            $this->maxProducts,
        );

        if ([] === $products) {
            return $this->generator->generate($context);
        }

        $out = $this->generator->generate($context)."\n---\n\n## Products\n";

        foreach ($products as $product) {
            $entry = $this->render($product, $context);

            // Stop between entries, never inside one: a truncated product reads
            // as a complete one with missing facts.
            if (\strlen($out) + \strlen($entry) > $this->maxBytes) {
                return rtrim($out."\n_Truncated at the size limit — the sitemap lists the full catalogue._\n")."\n";
            }

            $out .= $entry;
        }

        if (\count($products) >= $this->maxProducts) {
            $out .= \sprintf(
                "\n_Showing the %d most recent products — the sitemap lists the full catalogue._\n",
                $this->maxProducts,
            );
        }

        return rtrim($out)."\n";
    }

    private function render(ProductInterface $product, AgentContext $context): string
    {
        $translation = $product->getTranslation($context->localeCode);

        // The description below is already flattened by the strip_tags/\s+ pass.
        // The name was not, and it is the more exposed of the two: it reaches
        // here straight from the catalogue, and a newline in it ends the heading
        // and lets the remainder open sections of its own.
        $out = "\n### ".MarkdownText::inline($translation->getName() ?? (string) $product->getCode())."\n";

        $slug = $translation->getSlug();
        if (null !== $slug && '' !== $slug) {
            $out .= "\n".$context->url('/'.$context->localeCode.'/products/'.$slug)."\n";
        }

        $description = $translation->getShortDescription();
        if (null === $description || '' === trim($description)) {
            $description = $translation->getDescription();
        }

        if (null !== $description && '' !== trim($description)) {
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($description)));
            $out .= "\n".(mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'…' : $text)."\n";
        }

        return $out;
    }
}
