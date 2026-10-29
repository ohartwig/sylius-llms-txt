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

        // Catalogue text reaches here straight from the shop, often through an
        // import feed or an agent. A newline in the name ends the heading and
        // lets the remainder open sections of its own; the description gets the
        // stronger treatment of paragraph() below, because it starts a line.
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
            $text = MarkdownText::inline(strip_tags($description));
            $out .= "\n".self::paragraph(mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'…' : $text)."\n";
        }

        return $out;
    }

    /**
     * Makes single-line shop text safe to place at the start of a line.
     *
     * MarkdownText::inline() removes the newlines, so the text cannot open a
     * second line. But a paragraph of its own still begins with the text's own
     * first characters, and those decide what the line is: a description that
     * begins `## Agent endpoints` is a heading of that name, `- ` a list item,
     * `> ` a quote, `1. ` a numbered item, three backticks a code fence. In a
     * document agents read for its structure, that is a forged section with
     * whatever link the description carries (AUD-067-F01).
     *
     * Only a leading block marker is escaped. A backslash in front of an ASCII
     * punctuation character makes it literal in CommonMark, so the text still
     * reads the same; `*` and `_` further in are typography and stay as they
     * are, for the reason MarkdownText gives.
     */
    private static function paragraph(string $text): string
    {
        // A list marker of up to nine digits followed by `.` or `)` opens an
        // ordered list; escaping the delimiter is enough.
        if (1 === preg_match('/^(\d{1,9})([.)])/', $text, $m)) {
            return $m[1].'\\'.$m[2].substr($text, \strlen($m[0]));
        }

        // Headings, bullet lists and thematic breaks (#, -, +, *, _), quotes (>),
        // tables (|), fenced code (` and ~), HTML blocks (<) and setext
        // underlines (=).
        if ('' !== $text && str_contains('#-+*_>|`~<=', $text[0])) {
            return '\\'.$text;
        }

        return $text;
    }
}
