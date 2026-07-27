<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Storage;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache for the generated documents.
 *
 * Deliberately thin. What matters is that generation happens once per channel
 * and locale, and that every key comes from AgentContext::cacheKey() — so a
 * multi-channel shop cannot serve one channel's document under another's
 * hostname.
 */
final class LlmsTxtStore
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl = 3600,
    ) {
    }

    /** @param callable(): string $generate */
    public function get(string $key, callable $generate): string
    {
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $cached = $item->get();
            if (\is_string($cached)) {
                return $cached;
            }
        }

        $value = $generate();
        $item->set($value)->expiresAfter($this->ttl);
        $this->cache->save($item);

        return $value;
    }

    public function find(string $key): ?string
    {
        $item = $this->cache->getItem($key);
        $value = $item->isHit() ? $item->get() : null;

        return \is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, ?int $ttl = null): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value)->expiresAfter($ttl ?? $this->ttl);
        $this->cache->save($item);
    }
}
