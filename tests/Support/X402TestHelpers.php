<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Shared test helpers used by both `X402CallToolTest` and `X402ReadResourceTest`
 * (and future `X402GetPromptTest`). Pest loads each test file independently
 * when filtered, so helpers defined inside a single test file disappear when
 * another file's tests run in isolation. This file is required from
 * `tests/Pest.php` so the helpers are always available regardless of which
 * subset Pest is running.
 */
if (! class_exists(StubFacilitator::class, autoload: false)) {
    /**
     * Default-success facilitator for tests. Toggle `verifyOk`/`settleOk`
     * via constructor to drive the rejection paths.
     */
    final readonly class StubFacilitator implements FacilitatorClient
    {
        public function __construct(
            public bool $verifyOk = true,
            public bool $settleOk = true,
        ) {}

        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            return new VerifyResult($this->verifyOk, $this->verifyOk ? null : 'rejected', '0xpayer');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            return new SettleResult($this->settleOk, $this->settleOk ? '0xtxhash' : '', $challenge->network, '0xpayer', $this->settleOk ? null : 'failed');
        }

        public function supported(): SupportedKinds
        {
            return new SupportedKinds(kinds: []);
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
        }
    }
}

if (! function_exists('buildPaymentMeta')) {
    /**
     * Build a v2 PaymentSignature wire payload suitable for stuffing into
     * `params._meta["x402/payment"]`. Each call generates a fresh nonce
     * so consecutive uses don't trip replay protection unless intentional.
     *
     * @return array<string, mixed>
     */
    function buildPaymentMeta(string $payTo): array
    {
        return [
            'x402Version' => 2,
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'payload' => [
                'signature' => '0xdeadbeef',
                'authorization' => [
                    'from' => '0xfrom',
                    'to' => $payTo,
                    'value' => '10000',
                    'validAfter' => Date::now()->getTimestamp() - 10,
                    'validBefore' => Date::now()->getTimestamp() + 60,
                    'nonce' => '0x' . bin2hex(random_bytes(32)),
                ],
            ],
        ];
    }
}

if (! class_exists(PaidEchoTool::class, autoload: false)) {
    // Shared by handler tests + list-handler tests; PHP forbids redeclaring
    // a class, so any fixture referenced from two test files lives here.
    #[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
    final class PaidEchoTool extends Tool
    {
        public function description(): string
        {
            return 'Paid echo tool for tests.';
        }

        public function handle(Request $request): Response
        {
            return Response::json(['echo' => 'paid']);
        }
    }

    final class FreeEchoTool extends Tool
    {
        public function description(): string
        {
            return 'Free echo tool for tests.';
        }

        public function handle(Request $request): Response
        {
            return Response::json(['echo' => 'free']);
        }
    }

    #[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
    final class PaidEchoResource extends Resource
    {
        protected string $uri = 'mcp://test/paid-echo';

        public function description(): string
        {
            return 'Paid echo resource for tests.';
        }

        public function handle(): Response
        {
            return Response::text('paid resource body');
        }
    }

    final class FreeEchoResource extends Resource
    {
        protected string $uri = 'mcp://test/free-echo';

        public function description(): string
        {
            return 'Free echo resource for tests.';
        }

        public function handle(): Response
        {
            return Response::text('free resource body');
        }
    }

    #[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
    final class PaidEchoPrompt extends Prompt
    {
        public function description(): string
        {
            return 'Paid echo prompt for tests.';
        }

        public function handle(): Response
        {
            return Response::text('paid prompt body');
        }
    }

    final class FreeEchoPrompt extends Prompt
    {
        public function description(): string
        {
            return 'Free echo prompt for tests.';
        }

        public function handle(): Response
        {
            return Response::text('free prompt body');
        }
    }
}

if (! function_exists('expectedReceipt')) {
    /**
     * The settlement receipt every test asserts on. The `StubFacilitator`
     * defaults pin the four fields; if any change, this helper is the
     * single edit point.
     *
     * @return array{success: true, transaction: string, network: string, payer: string}
     */
    function expectedReceipt(): array
    {
        return [
            'success' => true,
            'transaction' => '0xtxhash',
            'network' => 'eip155:8453',
            'payer' => '0xpayer',
        ];
    }
}
