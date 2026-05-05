<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server;

use Closure;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Support\NetworkResolver;
use X402\Laravel\Support\PriceParser;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;

/**
 * MCP-side payment middleware. Intercepts `tools/call`, looks up the tool's
 * #[X402Price] attribute, and runs the payment dance before letting the
 * tool execute.
 *
 * Works only on the Streamable HTTP transport — stdio has no way to return
 * HTTP headers, so paid tools are treated as free on stdio (operator must
 * gate at the host level via auth, or run only HTTP transport in production).
 *
 * @internal Wired by X402McpServiceProvider.
 */
final class McpPaymentMiddleware
{
    public function __construct(
        private readonly FacilitatorClient $facilitator,
        private readonly NonceStoreContract $nonceStore,
        private readonly Registrar $registrar,
    ) {}

    public function handle(McpRequest $mcpRequest, Closure $next): mixed
    {
        $httpRequest = $mcpRequest->httpRequest();

        if (! $httpRequest instanceof HttpRequest) {
            return $next($mcpRequest);
        }

        $toolName = $this->extractToolName($mcpRequest);

        if ($toolName === null) {
            return $next($mcpRequest);
        }

        $tool = $this->resolveTool($toolName);

        if ($tool === null) {
            return $next($mcpRequest);
        }

        $price = $this->priceAttribute($tool);

        if ($price === null) {
            return $next($mcpRequest);
        }

        $challenge = $this->buildChallenge($price, $toolName);
        $version = Version::from((string) config('x402.version', 'v1'));

        $headerLine = (string) $httpRequest->headers->get($version->signatureHeader(), '');

        if ($headerLine === '') {
            return $this->paymentRequiredResponse($challenge, $version);
        }

        try {
            $signature = PaymentSignature::fromHeader($headerLine);
            (new ExactScheme)->verifyShape($signature, $challenge);
            $this->guardReplay($signature);
        } catch (\X402\Exceptions\InvalidPaymentException $e) {
            return $this->paymentRequiredResponse($challenge, $version, $e->getMessage());
        }

        $verify = $this->facilitator->verify($signature, $challenge);
        if (! $verify->isValid) {
            return $this->paymentRequiredResponse($challenge, $version, $verify->invalidReason);
        }

        $settle = $this->facilitator->settle($signature, $challenge);
        if (! $settle->success) {
            return $this->paymentRequiredResponse($challenge, $version, $settle->errorReason);
        }

        $response = $next($mcpRequest);

        if ($response instanceof Response) {
            $response->headers->set($version->responseHeader(), base64_encode((string) json_encode([
                'success' => true,
                'transaction' => $settle->transaction,
                'network' => $settle->network,
                'payer' => $settle->payer,
            ])));
        }

        return $response;
    }

    private function extractToolName(McpRequest $mcpRequest): ?string
    {
        // laravel/mcp dispatches per-method — the request exposes the params
        // for the active JSON-RPC call. We only care about tools/call.
        $method = method_exists($mcpRequest, 'method') ? (string) $mcpRequest->method() : '';
        if ($method !== 'tools/call') {
            return null;
        }

        $params = method_exists($mcpRequest, 'params') ? (array) $mcpRequest->params() : [];

        return isset($params['name']) ? (string) $params['name'] : null;
    }

    private function resolveTool(string $name): ?Tool
    {
        foreach ($this->registrar->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function priceAttribute(Tool $tool): ?X402Price
    {
        $attrs = (new ReflectionClass($tool))->getAttributes(X402Price::class);

        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    private function buildChallenge(X402Price $price, string $resource): PaymentRequired
    {
        $assetConfig = (array) config('x402.asset', []);
        $eip712 = is_array($assetConfig['eip712'] ?? null) ? $assetConfig['eip712'] : [];

        $atomic = PriceParser::toAtomic($price->amount, (int) ($assetConfig['decimals'] ?? 6));

        return new PaymentRequired(
            scheme: 'exact',
            network: NetworkResolver::toCaip2($price->network),
            maxAmountRequired: $atomic,
            asset: (string) ($assetConfig['address'] ?? ''),
            payTo: $price->payTo ?? (string) config('x402.recipient', ''),
            maxTimeoutSeconds: (int) config('x402.max_timeout_seconds', 60),
            resource: 'mcp://tool/'.$resource,
            description: $price->asset.' payment for MCP tool '.$resource,
            extra: [
                'name' => (string) ($eip712['name'] ?? ''),
                'version' => (string) ($eip712['version'] ?? '2'),
            ],
        );
    }

    private function guardReplay(PaymentSignature $signature): void
    {
        $auth = (array) ($signature->payload['authorization'] ?? []);
        $from = (string) ($auth['from'] ?? '');
        $nonce = (string) ($auth['nonce'] ?? '');
        $validBefore = (int) ($auth['validBefore'] ?? 0);
        $ttl = max(60, $validBefore - time() + 30);

        if (! $this->nonceStore->claim($signature->network, $from, $nonce, $ttl)) {
            throw new \X402\Exceptions\InvalidPaymentException('Nonce already used (replay attempt).');
        }
    }

    private function paymentRequiredResponse(PaymentRequired $challenge, Version $version, ?string $reason = null): Response
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32402,
                'message' => $reason ?? 'Payment required',
                'data' => [
                    'x402' => [
                        'x402Version' => $version === Version::V2 ? '2' : '1',
                        'accepts' => [$challenge->toArray()],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        return new Response($body, 402, [
            'Content-Type' => 'application/json',
            $version->challengeHeader() => base64_encode($body),
        ]);
    }
}
