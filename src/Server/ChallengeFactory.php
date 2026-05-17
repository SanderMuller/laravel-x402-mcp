<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server;

use Illuminate\Contracts\Config\Repository;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Support\ConfigReader;
use X402\Protocol\PaymentRequired;
use X402\Schemes\Evm\NetworkRegistry;
use X402\Support\PriceParser;

/**
 * Single-source builder for the `PaymentRequired` challenge served on a 402.
 * Lifts the asset / eip712 / decimals / recipient / timeout walk that used
 * to be duplicated across the three handler `buildChallenge` methods, so a
 * config-shape change (asset name swap, eip712 version bump) edits once.
 *
 * **Scope.** This factory does NOT prepend `mcp://tool/`, raw URI, or
 * `mcp://prompt/` — those are primitive-specific. Callers pass a
 * pre-formed `$resource` URI and `$description` sentence; the factory
 * reads config and emits the `PaymentRequired` envelope.
 */
final readonly class ChallengeFactory
{
    public function __construct(
        private Repository $config,
    ) {}

    public function build(X402Price $price, string $resource, string $description): PaymentRequired
    {
        $assetConfig = ConfigReader::array($this->config, 'x402.asset');
        $eip712Raw = $assetConfig['eip712'] ?? [];
        $eip712 = is_array($eip712Raw) ? $eip712Raw : [];

        $decimalsRaw = $assetConfig['decimals'] ?? 6;
        $decimals = is_int($decimalsRaw) ? $decimalsRaw : 6;
        $atomic = PriceParser::toAtomic($price->amount, $decimals);

        $address = $assetConfig['address'] ?? '';
        $assetAddress = is_string($address) ? $address : '';

        $name = $eip712['name'] ?? '';
        $version = $eip712['version'] ?? '2';

        return new PaymentRequired(
            scheme: 'exact',
            network: NetworkRegistry::toCaip2($price->network),
            amount: $atomic,
            asset: $assetAddress,
            payTo: $price->payTo ?? ConfigReader::string($this->config, 'x402.recipient'),
            maxTimeoutSeconds: ConfigReader::int($this->config, 'x402.max_timeout_seconds', 60),
            resource: $resource,
            description: $description,
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
            ],
        );
    }
}
