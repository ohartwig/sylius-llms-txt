<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests\Double;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A PSR-6 pool that remembers the lifetime each entry was saved with.
 *
 * The documents are stored with deliberately different lifetimes — the expanded
 * one for a day because only the dump command can rebuild it — and no
 * general-purpose adapter exposes that after the fact.
 */
final class RecordingCacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int|null> */
    public array $lifetimes = [];

    public function getItem(string $key): CacheItemInterface
    {
        return new RecordingCacheItem($this, $key);
    }

    /** @return iterable<string, CacheItemInterface> */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->lifetimes = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->values[$key], $this->lifetimes[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof RecordingCacheItem) {
            return false;
        }

        $this->values[$item->getKey()] = $item->get();
        $this->lifetimes[$item->getKey()] = $item->lifetime;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
