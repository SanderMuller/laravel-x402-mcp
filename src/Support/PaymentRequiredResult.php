<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Support;

/**
 * Helpers for client-side detection of "payment required" tool results.
 *
 * The x402 v2 MCP transport spec re-uses the HTTP code `402` as the
 * canonical signal (see `MCP_PAYMENT_REQUIRED_CODE` in the TS
 * reference). But the server emits a TOOL RESULT with `isError: true`
 * + `structuredContent: PaymentRequired`, NOT a JSON-RPC error envelope
 * carrying that code. Clients have to inspect `result.structuredContent`
 * to know payment is required.
 *
 * Use these helpers to keep that branching off your callers.
 */
final class PaymentRequiredResult
{
    /**
     * Spec / TS reference: `MCP_PAYMENT_REQUIRED_CODE = 402`. Exposed
     * here so callers don't have to hard-code the integer.
     */
    public const CODE = 402;

    /**
     * Inspect a tool-call result (`response.toArray()['result']`) and
     * return true when it carries a x402 payment-required payload.
     *
     * @param  array<string, mixed>  $toolResult
     */
    public static function matches(array $toolResult): bool
    {
        if (($toolResult['isError'] ?? false) !== true) {
            return false;
        }

        $structured = $toolResult['structuredContent'] ?? null;
        if (! is_array($structured)) {
            return false;
        }

        return isset($structured['x402Version'], $structured['accepts']);
    }

    /**
     * Extract the PaymentRequired body from a payment-required tool
     * result. Returns null when the result isn't payment-required.
     *
     * @param  array<string, mixed>  $toolResult
     * @return array<string, mixed>|null
     */
    public static function extract(array $toolResult): ?array
    {
        if (! self::matches($toolResult)) {
            return null;
        }

        $structured = $toolResult['structuredContent'] ?? null;
        if (! is_array($structured)) {
            return null;
        }

        /** @var array<string, mixed> $structured */
        return $structured;
    }
}
