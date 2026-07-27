<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use Koh\SyliusLlmsTxt\Agent\LlmsTxtEndpointProvider;
use Koh\SyliusLlmsTxt\Command\DumpLlmsTxtCommand;
use Koh\SyliusLlmsTxt\Controller\LlmsTxtController;
use Koh\SyliusLlmsTxt\Generator\LlmsFullGenerator;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\Provider\TaxonSectionProvider;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(LlmsTxtStore::class)
        ->args([service('cache.app'), param('koh_llms_txt.ttl')])
        ->public();

    $services->set(LlmsTxtGenerator::class)
        ->args([
            service(LlmsTxtSectionRegistry::class),
            service(AgentEndpointRegistry::class),
            param('koh_llms_txt.summary'),
            param('koh_llms_txt.intro'),
            param('koh_llms_txt.list_agent_endpoints'),
        ])
        ->public();

    $services->set(LlmsFullGenerator::class)
        ->args([
            service(LlmsTxtGenerator::class),
            service('sylius.repository.product'),
            param('koh_llms_txt.full.max_products'),
            param('koh_llms_txt.full.max_bytes'),
        ])
        ->public();

    $services->set(TaxonSectionProvider::class)
        ->args([
            service('sylius.repository.taxon'),
            param('koh_llms_txt.taxons.title'),
            param('koh_llms_txt.taxons.max_depth'),
            param('koh_llms_txt.taxons.limit'),
        ])
        ->tag('koh_agent.llms_txt_section');

    $services->set(LlmsTxtController::class)
        ->args([
            service(LlmsTxtGenerator::class),
            service(LlmsTxtStore::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(DumpLlmsTxtCommand::class)
        ->args([
            service(LlmsTxtGenerator::class),
            service(LlmsFullGenerator::class),
            service(LlmsTxtStore::class),
            service(AgentContextFactoryInterface::class),
            service('sylius.repository.channel'),
        ])
        ->tag('console.command');

    $services->set(LlmsTxtEndpointProvider::class)
        ->tag('koh_agent.api_catalog_entry');
};
