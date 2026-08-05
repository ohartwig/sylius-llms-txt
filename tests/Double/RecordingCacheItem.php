<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusLlmsTxt\Tests\Double;

use Psr\Cache\CacheItemInterface;

/** The item half of {@see RecordingCacheItemPool}. */
final class RecordingCacheItem implements CacheItemInterface
{
    public ?int $lifetime = null;

    private mixed $value = null;

    private readonly bool $hit;

    public function __construct(RecordingCacheItemPool $pool, private readonly string $key)
    {
        $this->hit = $pool->hasItem($key);
        if ($this->hit) {
            $this->value = $pool->values[$key];
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->lifetime = null === $expiration ? null : $expiration->getTimestamp() - time();

        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        $this->lifetime = $time instanceof \DateInterval ? (int) $time->format('%s') : $time;

        return $this;
    }
}
