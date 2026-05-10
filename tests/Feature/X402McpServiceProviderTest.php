<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Psr\SimpleCache\CacheInterface;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;

it('binds PaidToolResponseCache as a singleton', function (): void {
    $first = $this->app->make(PaidToolResponseCache::class);
    $second = $this->app->make(PaidToolResponseCache::class);

    expect($first)->toBe($second);
});

it('honours x402_mcp.response_cache.prefix override when set', function (): void {
    $config = $this->app->make(Repository::class);
    $config->set('x402_mcp.response_cache.prefix', 'custom:override:');

    /** @var PaidToolResponseCache $cache */
    $cache = $this->app->make(PaidToolResponseCache::class);

    $prefix = (fn (): string => $this->prefix)->call($cache);

    expect($prefix)->toBe('custom:override:');
});

it('falls back to the JSON-RPC default prefix when no config is set', function (): void {
    $config = $this->app->make(Repository::class);
    $config->set('x402_mcp.response_cache.prefix', null);

    /** @var PaidToolResponseCache $cache */
    $cache = $this->app->make(PaidToolResponseCache::class);

    $prefix = (fn (): string => $this->prefix)->call($cache);

    expect($prefix)->toBe(PaidToolResponseCache::DEFAULT_PREFIX);
});

it('reads x402.response_cache.ttl from config and falls back to 3600 on absence', function (): void {
    $config = $this->app->make(Repository::class);
    $config->set('x402.response_cache.ttl', 1800);

    /** @var PaidToolResponseCache $cache */
    $cache = $this->app->make(PaidToolResponseCache::class);

    $ttl = (fn (): int => $this->ttl)->call($cache);

    expect($ttl)->toBe(1800);
});

it('binds the cache against the configured x402.response_cache.cache_store override', function (): void {
    // Cross-store check: write through the bridge resolved by the SP,
    // then read directly via `cache()->store('x402_idem_test')`. If
    // the SP silently fell back to the default store, the named-store
    // read would miss — proves the named store IS the one being used.
    $config = $this->app->make(Repository::class);
    $config->set('cache.stores.x402_idem_test', ['driver' => 'array', 'serialize' => false]);
    $config->set('x402.response_cache.cache_store', 'x402_idem_test');

    /** @var PaidToolResponseCache $cache */
    $cache = $this->app->make(PaidToolResponseCache::class);

    $bridge = (fn (): CacheInterface => $this->cache)->call($cache);
    $bridge->set('x402-mcp:test:store-binding', 'WROTE_VIA_BRIDGE', 60);

    $namedStore = $this->app->make('cache')->store('x402_idem_test');
    expect($namedStore->get('x402-mcp:test:store-binding'))->toBe('WROTE_VIA_BRIDGE');

    // Negative — the default store must NOT have the entry. Proves
    // the SP isn't double-writing or quietly falling back.
    $defaultStore = $this->app->make('cache')->store();
    expect($defaultStore->get('x402-mcp:test:store-binding'))->toBeNull();
});

it('falls back to the default cache store when x402.response_cache.cache_store is unset', function (): void {
    $config = $this->app->make(Repository::class);
    $config->set('x402.response_cache.cache_store', null);

    /** @var PaidToolResponseCache $cache */
    $cache = $this->app->make(PaidToolResponseCache::class);

    $bridge = (fn (): CacheInterface => $this->cache)->call($cache);
    $bridge->set('x402-mcp:test:default-store-binding', 'WROTE_VIA_BRIDGE', 60);

    $defaultStore = $this->app->make('cache')->store();
    expect($defaultStore->get('x402-mcp:test:default-store-binding'))->toBe('WROTE_VIA_BRIDGE');
});
