<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves /llms.txt and /llms-full.txt.
 *
 * llms.txt is generated on demand behind a cache; llms-full.txt is only ever
 * served from the store. The expanded document walks the whole catalogue, and
 * building that inside a request either times out or hands an agent a way to
 * make the shop do expensive work on demand. `koh:agent:llms:dump` fills it.
 */
final class LlmsTxtController
{
    public function __construct(
        private readonly LlmsTxtGenerator $generator,
        private readonly LlmsTxtStore $store,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = $this->contextFactory->create();

        $body = $this->store->get(
            $context->cacheKey('koh_llms_txt'),
            fn (): string => $this->generator->generate($context),
        );

        return $this->responder->respondWithBody($body, 'text/plain', request: $request);
    }

    public function full(Request $request): Response
    {
        $context = $this->contextFactory->create();

        // Fall back to the short document rather than 404: an agent that asked
        // for the expanded form still gets something usable, and the link in
        // llms.txt does not dangle while the dump has not run yet.
        $body = $this->store->find($context->cacheKey('koh_llms_full'))
            ?? $this->store->get(
                $context->cacheKey('koh_llms_txt'),
                fn (): string => $this->generator->generate($context),
            );

        return $this->responder->respondWithBody($body, 'text/markdown', request: $request);
    }
}
