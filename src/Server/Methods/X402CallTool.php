<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Laravel\Mcp\Support\ValidationMessages;
use ReflectionClass;
use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Support\NetworkResolver;
use X402\Laravel\Support\ConfigReader;
use X402\Laravel\Support\PriceParser;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\ResourceInfo;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;
use X402\Support\JsonReader;

/**
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\CallTool` that gates
 * tools annotated with `#[X402Price]` behind an x402 payment, conformant
 * with the x402 v2 MCP transport spec (`specs/transports-v2/mcp.md`).
 *
 * Wire format (v2 spec):
 *
 *   - Client → server: payment payload in `params._meta["x402/payment"]`
 *     (a full PaymentPayload envelope), NOT in any HTTP header. The HTTP
 *     `PAYMENT-SIGNATURE` header is HTTP-transport-only — MCP rides on
 *     JSON-RPC, payment travels at the JSON-RPC level.
 *
 *   - Server → client (payment required): tool result with
 *     `isError: true`, `structuredContent` carrying the PaymentRequired
 *     object, and `content[0].text` carrying the JSON-stringified version
 *     for clients that don't grok structuredContent.
 *
 *   - Server → client (settled): normal tool result with the receipt in
 *     `result._meta["x402/payment-response"]`.
 *
 * The MCP error code for "payment required" is the literal HTTP code 402
 * (per `MCP_PAYMENT_REQUIRED_CODE` in the TS reference), but we do not
 * emit a JSON-RPC error envelope for the payment-required case — we emit
 * a tool result with `isError: true`. JSON-RPC errors stay reserved for
 * actual protocol violations.
 *
 * **Idempotency note:** the nonce is claimed BEFORE the facilitator
 * settles. Concurrent attack requests with the same authorization are
 * rejected without hitting the facilitator. If the facilitator's settle
 * fails after the claim, the nonce is burned and the user must
 * regenerate.
 */
final class X402CallTool extends CallTool
{
    private const META_REQUEST_KEY = 'x402/payment';

    private const META_RESPONSE_KEY = 'x402/payment-response';

    public function __construct(
        private readonly FacilitatorClient $facilitator,
        private readonly NonceStoreContract $nonceStore,
        private readonly Repository $config,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = $this->resolveTool($request, $context);

        if (! $tool instanceof Tool) {
            return parent::handle($request, $context);
        }

        $price = $this->priceAttribute($tool);

        if (! $price instanceof X402Price) {
            return parent::handle($request, $context);
        }

        $challenge = $this->buildChallenge($price, $tool->name());
        $paymentMeta = $this->readPaymentMeta($request);

        if ($paymentMeta === null) {
            return $this->paymentRequiredResult($request, $tool, $challenge, 'Payment required.');
        }

        try {
            $signature = $this->hydrateSignature($paymentMeta);
            (new ExactScheme())->verifyShape($signature, $challenge);
            $this->guardReplay($signature);
        } catch (InvalidPaymentException $invalidPaymentException) {
            return $this->paymentRequiredResult(
                $request,
                $tool,
                $challenge,
                $invalidPaymentException->getMessage(),
                $invalidPaymentException->reason?->value,
            );
        }

        $verify = $this->facilitator->verify($signature, $challenge);
        if (! $verify->isValid) {
            return $this->paymentRequiredResult(
                $request,
                $tool,
                $challenge,
                $verify->invalidReason ?? ErrorReason::UnexpectedVerifyError->value,
            );
        }

        $settle = $this->facilitator->settle($signature, $challenge);
        if (! $settle->success) {
            return $this->paymentRequiredResult(
                $request,
                $tool,
                $challenge,
                $settle->errorReason ?? ErrorReason::UnexpectedSettleError->value,
            );
        }

        return $this->runToolWithReceipt($request, $tool, $settle);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPaymentMeta(JsonRpcRequest $request): ?array
    {
        $meta = $request->params['_meta'] ?? null;
        if (! is_array($meta)) {
            return null;
        }

        $payment = $meta[self::META_REQUEST_KEY] ?? null;
        if (! is_array($payment)) {
            return null;
        }

        return $this->stringKeyed($payment);
    }

    /**
     * Narrow a JSON-decoded array to string-keyed entries — runtime
     * guarantee from `json_decode($s, true)` on an object input, but
     * PHPStan can't infer it from `mixed`.
     *
     * @param  array<int|string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function stringKeyed(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $paymentMeta
     */
    private function hydrateSignature(array $paymentMeta): PaymentSignature
    {
        $version = JsonReader::int($paymentMeta, 'x402Version', default: 2);

        $accepted = null;
        $acceptedRaw = $paymentMeta['accepted'] ?? null;
        if (is_array($acceptedRaw)) {
            $accepted = $this->hydrateAccepted($this->stringKeyed($acceptedRaw));
        }

        return new PaymentSignature(
            scheme: JsonReader::string($paymentMeta, 'scheme', 'x402/payment'),
            network: JsonReader::string($paymentMeta, 'network', 'x402/payment'),
            payload: JsonReader::array($paymentMeta, 'payload', 'x402/payment'),
            x402Version: $version,
            accepted: $accepted,
        );
    }

    /**
     * @param  array<string, mixed>  $accepted
     */
    private function hydrateAccepted(array $accepted): PaymentRequired
    {
        $amount = JsonReader::stringOrNull($accepted, 'amount')
            ?? JsonReader::string($accepted, 'maxAmountRequired', 'accepted');

        return new PaymentRequired(
            scheme: JsonReader::string($accepted, 'scheme', 'accepted'),
            network: JsonReader::string($accepted, 'network', 'accepted'),
            amount: $amount,
            asset: JsonReader::string($accepted, 'asset', 'accepted'),
            payTo: JsonReader::string($accepted, 'payTo', 'accepted'),
            maxTimeoutSeconds: JsonReader::int($accepted, 'maxTimeoutSeconds', default: 60),
            extra: JsonReader::arrayOrEmpty($accepted, 'extra'),
        );
    }

    private function paymentRequiredResult(
        JsonRpcRequest $request,
        Tool $tool,
        PaymentRequired $challenge,
        string $reason,
        ?string $errorReason = null,
    ): JsonRpcResponse {
        // Spec v2 §5.1: PaymentRequired body fields are
        // `{x402Version, resource, accepts, error?, extensions?}`. The
        // `errorReason` canonical enum lives on /verify and /settle response
        // shapes ONLY — putting it on the body here would be non-conformant.
        // Fold the canonical reason into the human-readable `error` field.
        $errorText = $errorReason !== null
            ? sprintf('%s (%s)', $reason, $errorReason)
            : $reason;

        $payload = [
            'x402Version' => 2,
            'error' => $errorText,
            'accepts' => [$challenge->toArrayV2()],
        ];

        $resourceInfo = $challenge->resourceInfo();
        if ($resourceInfo instanceof ResourceInfo) {
            $payload['resource'] = $resourceInfo->toArray();
        }

        $textBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Spec mandates BOTH structuredContent (for x402-aware clients) AND
        // content[0].text (JSON-stringified, for clients that haven't
        // adopted structuredContent yet). `Response::error` sets isError: true.
        $factory = (new ResponseFactory(Response::error($textBody)))
            ->withStructuredContent($payload);

        return $this->toJsonRpcResponse($request, $factory, $this->serializable($tool));
    }

    /**
     * @return Generator<int, JsonRpcResponse>|JsonRpcResponse
     */
    private function runToolWithReceipt(JsonRpcRequest $request, Tool $tool, SettleResult $settle): Generator|JsonRpcResponse
    {
        // Replicates the parent's runtime call so we can wrap the response
        // before serialisation. Auth/validation exceptions are caught here
        // so settled-but-rejected payments don't leak the tool result —
        // same shape as the parent's catch block.
        try {
            $response = Container::getInstance()->call([$tool, 'handle']);
        } catch (AuthorizationException|AuthenticationException $authException) {
            $response = Response::error($authException->getMessage());
        } catch (ValidationException $validationException) {
            $response = Response::error(ValidationMessages::from($validationException));
        }

        $receipt = [
            'success' => true,
            'transaction' => $settle->transaction,
            'network' => $settle->network,
            'payer' => $settle->payer,
        ];

        if (is_iterable($response)) {
            // Streamed responses don't get receipt injection in v0.1 — the
            // receipt would have to land in the FINAL chunk, which needs
            // generator interception. Tracked as a v0.x follow-up.
            /** @var iterable<Response|ResponseFactory|string> $response */
            return $this->toJsonRpcStreamedResponse($request, $response, $this->serializable($tool));
        }

        if ($response instanceof ResponseFactory) {
            $factory = $response;
        } elseif ($response instanceof Response) {
            $factory = new ResponseFactory($response);
        } else {
            // Unexpected return type from $tool->handle() — produce an
            // error result so we never leak `mixed` into the wire.
            $factory = new ResponseFactory(Response::error('Tool returned unexpected response type.'));
        }

        $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

        return $this->toJsonRpcResponse($request, $factory, $this->serializable($tool));
    }

    private function resolveTool(JsonRpcRequest $request, ServerContext $context): ?Tool
    {
        $name = $request->params['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }

        return $context->tools()->first(static fn (Tool $t): bool => $t->name() === $name);
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
            network: NetworkResolver::toCaip2($price->network),
            amount: $atomic,
            asset: $assetAddress,
            payTo: $price->payTo ?? ConfigReader::string($this->config, 'x402.recipient'),
            maxTimeoutSeconds: ConfigReader::int($this->config, 'x402.max_timeout_seconds', 60),
            resource: 'mcp://tool/' . $resource,
            description: $price->asset . ' payment for MCP tool ' . $resource,
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
            ],
        );
    }

    private function guardReplay(PaymentSignature $signature): void
    {
        $auth = JsonReader::arrayOrEmpty($signature->payload, 'authorization');
        $from = JsonReader::stringOrNull($auth, 'from') ?? '';
        $nonce = JsonReader::stringOrNull($auth, 'nonce') ?? '';
        $validBefore = JsonReader::int($auth, 'validBefore', default: 0);
        $ttl = max(60, $validBefore - Date::now()
            ->getTimestamp() + 30);

        if (! $this->nonceStore->claim($signature->network, $from, $nonce, $ttl)) {
            throw InvalidPaymentException::with(
                ErrorReason::ReplayAttempt,
                'Nonce already used (replay attempt).',
            );
        }
    }
}
