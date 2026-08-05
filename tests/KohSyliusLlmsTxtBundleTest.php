<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests;

use Koh\SyliusLlmsTxt\Agent\LlmsTxtEndpointProvider;
use Koh\SyliusLlmsTxt\Command\DumpLlmsTxtCommand;
use Koh\SyliusLlmsTxt\Controller\LlmsTxtController;
use Koh\SyliusLlmsTxt\Generator\LlmsFullGenerator;
use Koh\SyliusLlmsTxt\Generator\LlmsTxtGenerator;
use Koh\SyliusLlmsTxt\KohSyliusLlmsTxtBundle;
use Koh\SyliusLlmsTxt\Provider\TaxonSectionProvider;
use Koh\SyliusLlmsTxt\Storage\LlmsTxtStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle configuration is the shop operator's only interface to this
 * package, and its defaults are the shape of llms.txt for everyone who never
 * writes a line of configuration. A default that quietly changes — the product
 * cap, the byte budget, the depth cap — changes the document every installation
 * serves without anyone editing anything.
 */
final class KohSyliusLlmsTxtBundleTest extends TestCase
{
    public function testTheExtensionIsRegisteredUnderTheDocumentedAlias(): void
    {
        $extension = (new KohSyliusLlmsTxtBundle())->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('koh_llms_txt', $extension->getAlias());
    }

    /** config/ sits at the package root, not under src/, and the imports depend on it. */
    public function testThePathPointsAtThePackageRoot(): void
    {
        self::assertFileExists((new KohSyliusLlmsTxtBundle())->getPath().'/config/services.php');
    }

    public function testDefaultsAreTheDocumentedOnes(): void
    {
        $container = $this->load([]);

        self::assertNull($container->getParameter('koh_llms_txt.summary'));
        self::assertNull($container->getParameter('koh_llms_txt.intro'));
        self::assertSame(3600, $container->getParameter('koh_llms_txt.ttl'));
        self::assertTrue($container->getParameter('koh_llms_txt.list_agent_endpoints'));
        self::assertSame('Categories', $container->getParameter('koh_llms_txt.taxons.title'));
        self::assertSame(2, $container->getParameter('koh_llms_txt.taxons.max_depth'));
        self::assertSame(200, $container->getParameter('koh_llms_txt.taxons.limit'));
        self::assertSame(200, $container->getParameter('koh_llms_txt.full.max_products'));
        self::assertSame(1048576, $container->getParameter('koh_llms_txt.full.max_bytes'));
    }

    public function testEveryConfiguredValueReachesTheContainer(): void
    {
        $container = $this->load([
            'summary' => 'Cigars, pipe tobacco and accessories.',
            'intro' => 'Family-run since 1904.',
            'ttl' => 900,
            'list_agent_endpoints' => false,
            'taxons' => ['title' => 'Sortiment', 'max_depth' => 4, 'limit' => 25],
            'full' => ['max_products' => 50, 'max_bytes' => 262144],
        ]);

        self::assertSame('Cigars, pipe tobacco and accessories.', $container->getParameter('koh_llms_txt.summary'));
        self::assertSame('Family-run since 1904.', $container->getParameter('koh_llms_txt.intro'));
        self::assertSame(900, $container->getParameter('koh_llms_txt.ttl'));
        self::assertFalse($container->getParameter('koh_llms_txt.list_agent_endpoints'));
        self::assertSame('Sortiment', $container->getParameter('koh_llms_txt.taxons.title'));
        self::assertSame(4, $container->getParameter('koh_llms_txt.taxons.max_depth'));
        self::assertSame(25, $container->getParameter('koh_llms_txt.taxons.limit'));
        self::assertSame(50, $container->getParameter('koh_llms_txt.full.max_products'));
        self::assertSame(262144, $container->getParameter('koh_llms_txt.full.max_bytes'));
    }

    public function testTheServicesTheBundleExistsForAreRegistered(): void
    {
        $container = $this->load([]);

        foreach ([
            LlmsTxtStore::class,
            LlmsTxtGenerator::class,
            LlmsFullGenerator::class,
            LlmsTxtController::class,
            DumpLlmsTxtCommand::class,
            LlmsTxtEndpointProvider::class,
        ] as $id) {
            self::assertTrue($container->hasDefinition($id), $id.' must be registered');
        }
    }

    /** The section provider only reaches llms.txt through this tag. */
    public function testTheTaxonProviderIsTaggedAsAnLlmsTxtSection(): void
    {
        $definition = $this->load([])->getDefinition(TaxonSectionProvider::class);

        self::assertArrayHasKey('koh_agent.llms_txt_section', $definition->getTags());
    }

    /**
     * Turning the section off has to remove the service, not merely stop it
     * being read: a registered provider is still tagged, still constructed and
     * still queries the taxon repository on every request.
     */
    public function testDisablingTheTaxonSectionRemovesTheService(): void
    {
        $container = $this->load(['taxons' => ['enabled' => false]]);

        self::assertFalse($container->hasDefinition(TaxonSectionProvider::class));
        self::assertTrue($container->hasDefinition(LlmsTxtGenerator::class), 'only the section goes away');
    }

    public function testTheTaxonSectionIsOnByDefault(): void
    {
        self::assertTrue($this->load([])->hasDefinition(TaxonSectionProvider::class));
        self::assertTrue($this->load(['taxons' => ['enabled' => true]])->hasDefinition(TaxonSectionProvider::class));
    }

    /** A typo in a configuration key must fail the build, not be ignored. */
    public function testAnUnknownConfigurationKeyIsRejected(): void
    {
        try {
            $this->load(['taxonz' => ['limit' => 5]]);
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('taxonz', $exception->getMessage());

            return;
        }

        self::fail('an unrecognised configuration key must be rejected');
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new KohSyliusLlmsTxtBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
