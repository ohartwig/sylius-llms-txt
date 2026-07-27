<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-llms-txt

Serves `/llms.txt` and `/llms-full.txt` per [llmstxt.org](https://llmstxt.org/),
generated from the channel's own catalogue and content.

## Install

```bash
composer require koh/sylius-llms-txt
```

```php
// config/bundles.php
Koh\SyliusLlmsTxt\KohSyliusLlmsTxtBundle::class => ['all' => true],
```

```yaml
# config/routes/koh_agent.yaml
koh_llms_txt:
    resource: '@KohSyliusLlmsTxtBundle/config/routes.php'
```

## Configure

```yaml
# config/packages/koh_llms_txt.yaml
koh_llms_txt:
    summary: 'Cigars, pipe tobacco and accessories, shipped from Germany.'
    ttl: 3600

    taxons:
        enabled: true
        title: 'Categories'
        max_depth: 2
        limit: 200

    full:
        max_products: 200
        max_bytes: 1048576
```

## A map, not a catalogue

`llms.txt` lists the shape of the shop — categories, content sections, the
machine-readable endpoints — and stops. A shop with ten thousand products that
lists them all produces a document nobody can use and every crawler truncates.
Products are reachable through the sitemap and the taxon pages listed here.

`llms-full.txt` is the expanded form: the same document plus product detail,
bounded by **both** a product count and a byte budget. It stops *between*
entries and says it stopped — a truncated product reads as a complete one with
missing facts.

## Generating

```bash
bin/console koh:agent:llms:dump          # every enabled channel
bin/console koh:agent:llms:dump -c WEB_DE
bin/console koh:agent:llms:dump --print  # to stdout, stores nothing
```

`/llms.txt` is generated on demand behind a cache, so it works without the
command. **`/llms-full.txt` is only ever served from the store**: it walks the
catalogue, which is not work to do inside a request an agent controls. Until the
command has run, `/llms-full.txt` serves the short document rather than 404 —
the link in `llms.txt` should not dangle.

Run it from cron or a post-deploy hook.

## Adding your own sections

Implement `LlmsTxtSectionProviderInterface` from `koh/sylius-agent-contracts`.
Container-wide autoconfiguration picks it up, so a service in your own `App\`
namespace needs no wiring:

```php
final class ArticleSectionProvider implements LlmsTxtSectionProviderInterface
{
    public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection
    {
        $links = [];
        foreach ($this->articles->findPublished($context->channel, 100) as $article) {
            $links[] = new LlmsTxtLink($article->getTitle(), $context->url('/magazin/'.$article->getSlug()));
        }

        return [] === $links ? null : new LlmsTxtSection('Magazine', $links, priority: 80);
    }
}
```

Higher priority sorts earlier. Return `null` to contribute nothing for a channel.

## Caching

Every key goes through `AgentContext::cacheKey()`, so it carries the channel and
locale. Skipping that is the classic multi-channel bug: one channel warms the
cache and every other channel is served its document — under the wrong hostname,
with the wrong URLs.

## Decommission

Remove the `composer require`, the bundle entry, the route import and
`config/packages/koh_llms_txt.yaml`, then drop the cron entry. Cached documents
expire on their own; `cache:pool:clear cache.app` removes them at once.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
