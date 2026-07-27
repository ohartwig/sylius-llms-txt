<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Generator;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;

/**
 * Builds /llms.txt for one channel.
 *
 * A map, not a catalogue. llmstxt.org asks for an overview an agent can read in
 * one go and navigate from; a shop with ten thousand products that lists them
 * all produces a document nobody can use and every crawler truncates. Products
 * are reachable through the sitemap and the taxon pages listed here.
 */
final class LlmsTxtGenerator
{
    public function __construct(
        private readonly LlmsTxtSectionRegistry $sections,
        private readonly AgentEndpointRegistry $endpoints,
        private readonly ?string $summary = null,
        private readonly ?string $intro = null,
        private readonly bool $listAgentEndpoints = true,
    ) {
    }

    public function generate(AgentContext $context): string
    {
        $out = '# '.($context->channel->getName() ?? 'Shop')."\n";

        if (null !== $this->summary && '' !== $this->summary) {
            $out .= "\n> ".$this->summary."\n";
        }

        if (null !== $this->intro && '' !== $this->intro) {
            $out .= "\n".$this->intro."\n";
        }

        foreach ($this->sections->all($context) as $section) {
            $out .= "\n".$section->toMarkdown();
        }

        if ($this->listAgentEndpoints) {
            $out .= $this->agentEndpoints($context);
        }

        return rtrim($out)."\n";
    }

    /**
     * The machine-readable surface, listed from the same registry that serves
     * it — so this section cannot advertise an endpoint the shop does not have.
     */
    private function agentEndpoints(AgentContext $context): string
    {
        $endpoints = $this->endpoints->all($context);
        if ([] === $endpoints) {
            return '';
        }

        $out = "\n## Agent endpoints\n\n";
        foreach ($endpoints as $endpoint) {
            $out .= \sprintf('- [%s](%s)', $endpoint->title, $context->url($endpoint->path));
            if (null !== $endpoint->description) {
                $out .= ': '.$endpoint->description;
            }
            $out .= "\n";
        }

        return $out;
    }
}
