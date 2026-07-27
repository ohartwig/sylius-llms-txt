<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Serves /llms.txt and /llms-full.txt.
 */
final class KohSyliusLlmsTxtBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_llms_txt';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('summary')
                    ->defaultNull()
                    ->info('One-line blockquote under the heading. Say what the shop sells.')
                ->end()
                ->scalarNode('intro')->defaultNull()->end()
                ->integerNode('ttl')->defaultValue(3600)->end()
                ->booleanNode('list_agent_endpoints')->defaultTrue()->end()
                ->arrayNode('taxons')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('title')->defaultValue('Categories')->end()
                        ->integerNode('max_depth')->defaultValue(2)->end()
                        ->integerNode('limit')->defaultValue(200)->end()
                    ->end()
                ->end()
                ->arrayNode('full')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_products')->defaultValue(200)->end()
                        ->integerNode('max_bytes')->defaultValue(1048576)->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $taxons = \is_array($config['taxons'] ?? null) ? $config['taxons'] : [];
        $full = \is_array($config['full'] ?? null) ? $config['full'] : [];

        $builder->setParameter('koh_llms_txt.summary', $config['summary'] ?? null);
        $builder->setParameter('koh_llms_txt.intro', $config['intro'] ?? null);
        $builder->setParameter('koh_llms_txt.ttl', (int) ($config['ttl'] ?? 3600));
        $builder->setParameter('koh_llms_txt.list_agent_endpoints', (bool) ($config['list_agent_endpoints'] ?? true));
        $builder->setParameter('koh_llms_txt.taxons.title', (string) ($taxons['title'] ?? 'Categories'));
        $builder->setParameter('koh_llms_txt.taxons.max_depth', (int) ($taxons['max_depth'] ?? 2));
        $builder->setParameter('koh_llms_txt.taxons.limit', (int) ($taxons['limit'] ?? 200));
        $builder->setParameter('koh_llms_txt.full.max_products', (int) ($full['max_products'] ?? 200));
        $builder->setParameter('koh_llms_txt.full.max_bytes', (int) ($full['max_bytes'] ?? 1048576));

        $container->import('../config/services.php');

        if (!($taxons['enabled'] ?? true)) {
            $builder->removeDefinition(Provider\TaxonSectionProvider::class);
        }
    }
}
