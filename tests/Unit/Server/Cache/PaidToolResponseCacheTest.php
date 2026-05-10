<?php

declare(strict_types=1);

use DateInterval;
use Psr\Log\AbstractLogger;
use Psr\SimpleCache\CacheInterface;
use X402\Laravel\Mcp\Server\Cache\CacheScope;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Protocol\PaymentSignature;

/**
 * In-memory PSR-16 store for the cache adapter tests. Mirrors the shape
 * `php-x402`'s own `IdempotencyArrayCache` test fixture uses.
 */
class CacheArray implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    /**
     * @param  iterable<mixed>  $keys
     * @return array{}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param  iterable<mixed>  $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    /**
     * @param  iterable<mixed>  $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
}

function paidSignature(string $signatureField = '0xLEGIT-SIG', string $nonce = '0xnonce', string $from = '0xfrom'): PaymentSignature
{
    return new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => $signatureField,
            'authorization' => [
                'from' => $from,
                'nonce' => $nonce,
                'validBefore' => 9999999999,
            ],
        ],
    );
}

it('round-trips a stored snapshot under the same scope and signature', function (): void {
    $cache = new PaidToolResponseCache(new CacheArray());
    $scope = CacheScope::forToolCall('echo', ['hi' => 1]);
    $sig = paidSignature();

    expect($cache->lookup($scope, $sig))->toBeNull();

    $cache->store($scope, $sig, ['result' => ['echo' => 'hi']]);

    expect($cache->lookup($scope, $sig))->toBe(['result' => ['echo' => 'hi']]);
});

it('produces a miss when the EIP-3009 signature differs but the auth tuple matches (forge guard)', function (): void {
    $cache = new PaidToolResponseCache(new CacheArray());
    $scope = CacheScope::forToolCall('echo', ['hi' => 1]);

    $cache->store($scope, paidSignature(signatureField: '0xLEGIT-SIG'), ['result' => ['echo' => 'hi']]);

    expect($cache->lookup($scope, paidSignature(signatureField: '0xATTACKER-FORGED')))->toBeNull();
});

it('isolates entries across primitives: tools/call cache does not satisfy resources/read retry', function (): void {
    $cache = new PaidToolResponseCache(new CacheArray());
    $sig = paidSignature();

    $cache->store(CacheScope::forToolCall('foo', []), $sig, ['result' => ['from' => 'tool']]);

    expect($cache->lookup(CacheScope::forResourceRead('mcp://x/something'), $sig))->toBeNull()
        ->and($cache->lookup(CacheScope::forPromptGet('foo', []), $sig))->toBeNull();
});

it('isolates HasUriTemplate concrete URIs from each other under the same priced template', function (): void {
    $cache = new PaidToolResponseCache(new CacheArray());
    $sig = paidSignature();

    $cache->store(CacheScope::forResourceRead('mcp://x/users/1'), $sig, ['result' => ['user' => 1]]);

    expect($cache->lookup(CacheScope::forResourceRead('mcp://x/users/2'), $sig))->toBeNull();
});

it('returns null when the signature has no signature field (defense against a transport that forgot to validate)', function (): void {
    $cache = new PaidToolResponseCache(new CacheArray());
    $scope = CacheScope::forToolCall('echo', []);

    $missingSigField = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            // 'signature' deliberately absent
            'authorization' => ['from' => '0xfrom', 'nonce' => '0xnonce', 'validBefore' => 9999999999],
        ],
    );

    expect($cache->lookup($scope, $missingSigField))->toBeNull();

    // store() must also be a no-op so a missing-signature path can't poison the cache.
    $cache->store($scope, $missingSigField, ['result' => ['leak' => true]]);

    $store = (fn (): CacheInterface => $this->cache)->call($cache);

    /** @var CacheArray $arrayStore */
    $arrayStore = $store;
    expect($arrayStore->store)
        ->toBeEmpty();
});

it('returns null when the authorization payload is missing required fields', function (): void {
    $cache = new PaidToolResponseCache(new CacheArray());
    $scope = CacheScope::forToolCall('echo', []);

    $brokenAuth = new PaymentSignature(
        scheme: 'exact',
        network: 'eip155:8453',
        payload: [
            'signature' => '0xsig',
            // 'authorization' missing — `PaymentSignature::authorization()` returns null.
        ],
    );

    expect($cache->lookup($scope, $brokenAuth))->toBeNull();
});

it('falls through on a malformed cached snapshot (poisoned cache resilience)', function (): void {
    $store = new CacheArray();
    $cache = new PaidToolResponseCache($store);
    $scope = CacheScope::forToolCall('echo', []);
    $sig = paidSignature();

    // Pre-poison the cache at the legitimate key with a non-snapshot value.
    $cache->store($scope, $sig, ['result' => ['ok' => true]]);
    foreach (array_keys($store->store) as $key) {
        $store->store[$key] = ['this is not a valid snapshot'];
    }

    expect($cache->lookup($scope, $sig))->toBeNull();
});

it('honours a custom prefix for HTTP/JSON-RPC namespace isolation on a shared Redis', function (): void {
    $store = new CacheArray();
    $cache = new PaidToolResponseCache($store, prefix: 'custom:idem:mcp:');
    $scope = CacheScope::forToolCall('echo', []);
    $sig = paidSignature();

    $cache->store($scope, $sig, ['result' => []]);

    /** @var list<string> $keys */
    $keys = array_keys($store->store);
    expect($keys)->toHaveCount(1)
        ->and($keys[0])->toStartWith('custom:idem:mcp:');
});

it('logs a warning when the underlying cache set() returns false (degraded idempotency surfaced)', function (): void {
    $store = new class extends CacheArray {
        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
        {
            return false;
        }
    };

    $logged = [];
    $logger = new class ($logged) extends AbstractLogger {
        /** @param  list<array{level: string, message: string}>  $logged */
        public function __construct(public array &$logged) {}

        /** @param  array<array-key, mixed>  $context */
        public function log(mixed $level, Stringable|string $message, array $context = []): void
        {
            $this->logged[] = [
                'level' => is_string($level) ? $level : 'unknown',
                'message' => (string) $message,
            ];
        }
    };

    $cache = new PaidToolResponseCache($store, logger: $logger);

    $cache->store(CacheScope::forToolCall('echo', []), paidSignature(), ['result' => ['ok' => true]]);

    $warnings = array_values(array_filter($logged, static fn (array $e): bool => $e['level'] === 'warning'));

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0]['message'])->toContain('cache write returned false');
});

it('degrades to a cache miss when the underlying cache get() throws — does not crash the handler before settle', function (): void {
    $store = new class extends CacheArray {
        public function get(string $key, mixed $default = null): mixed
        {
            throw new RuntimeException('redis disconnected');
        }
    };

    $logged = [];
    $logger = new class ($logged) extends AbstractLogger {
        /** @param  list<array{level: string, message: string}>  $logged */
        public function __construct(public array &$logged) {}

        /** @param  array<array-key, mixed>  $context */
        public function log(mixed $level, Stringable|string $message, array $context = []): void
        {
            $this->logged[] = [
                'level' => is_string($level) ? $level : 'unknown',
                'message' => (string) $message,
            ];
        }
    };

    $cache = new PaidToolResponseCache($store, logger: $logger);

    // Lookup happens BEFORE settle in the call path. If this threw,
    // the request would crash as a -32603 JSON-RPC error and the user
    // could not pay. Degrading to "miss" lets the normal settle path run.
    $result = $cache->lookup(CacheScope::forToolCall('echo', []), paidSignature());

    $warnings = array_values(array_filter($logged, static fn (array $e): bool => $e['level'] === 'warning'));

    expect($result)->toBeNull()
        ->and($warnings)->not->toBeEmpty()
        ->and($warnings[0]['message'])->toContain('cache read threw');
});

it('logs a warning when the underlying cache set() throws (operator-visible degradation)', function (): void {
    $store = new class extends CacheArray {
        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
        {
            throw new RuntimeException('redis down');
        }
    };

    $logged = [];
    $logger = new class ($logged) extends AbstractLogger {
        /** @param  list<array{level: string, message: string}>  $logged */
        public function __construct(public array &$logged) {}

        /** @param  array<array-key, mixed>  $context */
        public function log(mixed $level, Stringable|string $message, array $context = []): void
        {
            $this->logged[] = [
                'level' => is_string($level) ? $level : 'unknown',
                'message' => (string) $message,
            ];
        }
    };

    $cache = new PaidToolResponseCache($store, logger: $logger);

    // store() must not let the exception escape — settlement already
    // happened, throwing here would propagate as an HTTP 500 to the
    // caller. Logging is the right surface.
    $cache->store(CacheScope::forToolCall('echo', []), paidSignature(), ['result' => ['ok' => true]]);

    $warnings = array_values(array_filter($logged, static fn (array $e): bool => $e['level'] === 'warning'));

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0]['message'])->toContain('cache write threw');
});

it('uses the JSON-RPC default prefix x402:idem:mcp: out of the box', function (): void {
    $store = new CacheArray();
    $cache = new PaidToolResponseCache($store);

    $cache->store(CacheScope::forToolCall('echo', []), paidSignature(), ['result' => []]);

    /** @var list<string> $keys */
    $keys = array_keys($store->store);
    expect($keys[0])->toStartWith('x402:idem:mcp:');
});
