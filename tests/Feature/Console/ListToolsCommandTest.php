<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use X402\Laravel\Mcp\Attributes\X402Price;

#[X402Price(amount: '0.05', asset: 'USDC', network: 'base-sepolia', payTo: '0x000000000000000000000000000000000000beef')]
final class ListCmdPaidTool extends Tool
{
    public function description(): string
    {
        return 'paid tool fixture';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['ok' => true]);
    }
}

final class ListCmdFreeTool extends Tool
{
    public function description(): string
    {
        return 'free tool fixture';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['ok' => true]);
    }
}

final class ListCmdServer extends Server
{
    /** @var array<int, class-string<Tool>|Tool> */
    protected array $tools = [
        ListCmdPaidTool::class,
        ListCmdFreeTool::class,
    ];
}

final class ListCmdEmptyServer extends Server
{
    /** @var array<int, class-string<Tool>|Tool> */
    protected array $tools = [];
}

final class ListCmdHiddenTool extends Tool
{
    public function description(): string
    {
        return 'hidden via shouldRegister';
    }

    public function shouldRegister(): bool
    {
        return false;
    }

    public function handle(Request $request): Response
    {
        return Response::json(['ok' => true]);
    }
}

final class ListCmdHidingServer extends Server
{
    /** @var array<int, class-string<Tool>|Tool> */
    protected array $tools = [
        ListCmdPaidTool::class,
        ListCmdHiddenTool::class,
    ];
}

#[X402Price(amount: '0.10', asset: 'USDC', network: 'base')]
final class ListCmdPaidResource extends Resource
{
    protected string $uri = 'mcp://test/list-cmd-paid-resource';

    public function description(): string
    {
        return 'paid resource fixture';
    }

    public function handle(): Response
    {
        return Response::text('ok');
    }
}

final class ListCmdPaidPrompt extends Prompt
{
    public function description(): string
    {
        return 'free prompt fixture for the mixed-server smoke test';
    }

    public function handle(): Response
    {
        return Response::text('ok');
    }
}

final class ListCmdMixedServer extends Server
{
    /** @var array<int, class-string<Tool>|Tool> */
    protected array $tools = [
        ListCmdPaidTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>|Server\Resource> */
    protected array $resources = [
        ListCmdPaidResource::class,
    ];

    /** @var array<int, class-string<Prompt>|Prompt> */
    protected array $prompts = [
        ListCmdPaidPrompt::class,
    ];
}

it('renders both priced and free tools', function (): void {
    $code = Artisan::call('x402-mcp:list-tools', [
        'server' => ListCmdServer::class,
    ]);

    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('list-cmd-paid-tool')
        ->and($output)->toContain('0.05')
        ->and($output)->toContain('USDC')
        ->and($output)->toContain('base-sepolia')
        ->and($output)->toContain('list-cmd-free-tool')
        ->and($output)->toContain('(free)');
});

it('reports when a server has no tools, resources, or prompts registered', function (): void {
    $this->artisan('x402-mcp:list-tools', ['server' => ListCmdEmptyServer::class])
        ->expectsOutputToContain('No tools, resources, or prompts registered')
        ->assertSuccessful();
});

it('errors when the argument is not a Server subclass', function (): void {
    $this->artisan('x402-mcp:list-tools', ['server' => stdClass::class])
        ->expectsOutputToContain('not a Laravel\\Mcp\\Server subclass')
        ->assertFailed();
});

it('renders tools, resources, and prompts together with a Type column', function (): void {
    Artisan::call('x402-mcp:list-tools', ['server' => ListCmdMixedServer::class]);

    $output = Artisan::output();

    expect($output)->toContain('Tool')
        ->and($output)->toContain('Resource')
        ->and($output)->toContain('Prompt')
        ->and($output)->toContain('list-cmd-paid-tool')
        ->and($output)->toContain('list-cmd-paid-resource')
        ->and($output)->toContain('list-cmd-paid-prompt')
        // Free entries — note the prompt fixture is free.
        ->and($output)->toContain('(free)');
});

it('hides tools whose shouldRegister returns false, matching tools/list', function (): void {
    Artisan::call('x402-mcp:list-tools', ['server' => ListCmdHidingServer::class]);

    $output = Artisan::output();

    expect($output)->toContain('list-cmd-paid-tool')
        ->and($output)->not->toContain('list-cmd-hidden-tool');
});
