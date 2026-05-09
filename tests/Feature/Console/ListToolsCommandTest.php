<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
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

it('reports when a server has no tools registered', function (): void {
    $this->artisan('x402-mcp:list-tools', ['server' => ListCmdEmptyServer::class])
        ->expectsOutputToContain('No tools registered')
        ->assertSuccessful();
});

it('errors when the argument is not a Server subclass', function (): void {
    $this->artisan('x402-mcp:list-tools', ['server' => stdClass::class])
        ->expectsOutputToContain('not a Laravel\\Mcp\\Server subclass')
        ->assertFailed();
});

it('hides tools whose shouldRegister returns false, matching tools/list', function (): void {
    Artisan::call('x402-mcp:list-tools', ['server' => ListCmdHidingServer::class]);

    $output = Artisan::output();

    expect($output)->toContain('list-cmd-paid-tool')
        ->and($output)->not->toContain('list-cmd-hidden-tool');
});
