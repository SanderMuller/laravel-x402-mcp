<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Cache;

use InvalidArgumentException;
use JsonException;

/**
 * Transport-specific scope segments fed into `IdempotencyKeyBuilder::build`
 * via its `$scope` parameter. Different scope = different cache key, even
 * with identical `(network, from, nonce, signature)`.
 *
 * Three named constructors, one per JSON-RPC method this package gates:
 *
 *   - `forToolCall($name, $arguments)` → `['tools/call', 'mcp://tool/{name}', sha256(canonical_args)]`
 *   - `forResourceRead($concreteUri)`  → `['resources/read', $concreteUri]` (no args hash; URI carries the full intent)
 *   - `forPromptGet($name, $arguments)` → `['prompts/get', 'mcp://prompt/{name}', sha256(canonical_args)]`
 *
 * **Why a value object instead of a `list<string>` at the call site.** Two
 * reasons. First, the scope shape MUST stay stable across retries — a
 * caller building the list inline can accidentally drift on key order or
 * delimiter conventions. Second, the args fingerprint requires
 * sort-keys-recursive canonical JSON encoding so a client-side
 * reserialisation doesn't split equivalent calls into separate cache
 * entries; centralising that here is the only way to keep the
 * canonicalisation honest.
 *
 * The args hash uses `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES` and a
 * recursive ksort. Two equivalent argument bags (different key order,
 * different escaping) collapse to the same hash. Distinct argument bags
 * collide at the same `2^-256` floor as plain `sha256`.
 */
final readonly class CacheScope
{
    /**
     * @param  list<string>  $segments
     */
    private function __construct(
        public array $segments,
    ) {}

    /**
     * Scope for a `tools/call` retry. The challenge resource is
     * `mcp://tool/{name}`; the args hash binds to the JSON-RPC
     * `params.arguments` so two calls of the same tool with different
     * arguments do not collide.
     *
     * **Argument-key types preserved.** `params.arguments` is a JSON
     * object on the wire, but PHP's `json_decode($s, true)` produces
     * integer-keyed entries for numeric-string JSON keys (`{"1":"a"}` →
     * `[1 => "a"]`). Pass the raw decoded array — including any
     * integer keys — without filtering: the canonical-args hash binds
     * to the structure as PHP sees it, so `{"1":"a"}` and `{"2":"b"}`
     * produce distinct hashes (Codex review fix; the prior helper
     * filtered to string keys and aliased numeric-keyed bags).
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public static function forToolCall(string $name, array $arguments): self
    {
        return new self([
            'tools/call',
            'mcp://tool/' . $name,
            self::canonicalArgsHash($arguments),
        ]);
    }

    /**
     * Scope for a `resources/read` retry. Resources are URI-addressed —
     * the concrete request URI IS the full intent, so no args hash is
     * needed. Two reads of `mcp://x/users/1` and `mcp://x/users/2`
     * already produce different scope segments and therefore different
     * cache keys, even if both resources sit under the same priced
     * `mcp://x/users/{id}` template.
     */
    public static function forResourceRead(string $concreteUri): self
    {
        return new self([
            'resources/read',
            $concreteUri,
        ]);
    }

    /**
     * Scope for a `prompts/get` retry. Same args-hash logic as
     * `forToolCall` — prompts are name-addressed and may carry
     * `params.arguments` that differentiate equivalent prompt fetches.
     * Integer-keyed entries (from numeric-string JSON keys) are
     * preserved by the canonical-args hash; see `forToolCall`.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public static function forPromptGet(string $name, array $arguments): self
    {
        return new self([
            'prompts/get',
            'mcp://prompt/' . $name,
            self::canonicalArgsHash($arguments),
        ]);
    }

    /**
     * Recursive ksort + canonical JSON encode + sha256. The ksort makes
     * `{"a": 1, "b": 2}` and `{"b": 2, "a": 1}` collapse to the same
     * hash; the canonical encoding flags neutralise whitespace /
     * escape-sequence drift; `JSON_THROW_ON_ERROR` surfaces a malformed
     * argument bag as an exception rather than a silently empty hash;
     * `JSON_PRESERVE_ZERO_FRACTION` distinguishes `1` from `1.0` (without
     * it, both encode as `1` and alias under the same hash).
     *
     * **Float-precision caveat.** PHP's `json_encode` honours the process
     * `serialize_precision` setting for non-integer floats. On a
     * heterogeneous fleet sharing one Redis, two PHP versions / configs
     * encoding the same float can produce different hashes and miss each
     * other's cache entries. Acceptable for v1 — operationally rare, and
     * a typed canonical form is a bigger lift.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    private static function canonicalArgsHash(array $arguments): string
    {
        try {
            return hash('sha256', json_encode(self::recursiveKsort($arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException $jsonException) {
            // A non-JSON-encodable argument (resource handle, recursive
            // structure) means the caller built a bag we cannot fingerprint
            // deterministically. Falling back to a constant hash would let
            // unrelated calls collide; throwing surfaces the bug at the
            // caller.
            throw new InvalidArgumentException('CacheScope cannot canonicalise arguments: ' . $jsonException->getMessage(), $jsonException->getCode(), previous: $jsonException);
        }
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private static function recursiveKsort(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::recursiveKsort($item);
            }
        }

        return $value;
    }
}
