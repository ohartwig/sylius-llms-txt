<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

/**
 * Publishes llms.txt as the shop's describedby link.
 *
 * Not an AgentEndpoint: llms.txt is where the endpoint list itself is rendered,
 * and announcing it there would have the document cite itself.
 */
final class LlmsTxtEndpointProvider implements ApiCatalogEntryProviderInterface
{
    public function getApiCatalogEntries(AgentContext $context): iterable
    {
        yield new ApiCatalogEntry(
            anchor: $context->baseUrl,
            describedBy: [
                new LinkTarget($context->url('/llms.txt'), 'text/plain'),
                new LinkTarget($context->url('/llms-full.txt'), 'text/markdown'),
            ],
            title: 'Shop overview for language models',
        );
    }
}
