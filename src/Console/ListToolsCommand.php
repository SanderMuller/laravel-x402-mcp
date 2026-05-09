<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use ReflectionProperty;
use X402\Laravel\Mcp\Attributes\X402Price;

/**
 * Inspect a Server class and list its tools, marking which ones are
 * gated by `#[X402Price]` and at what price. Mirrors `x402:list-routes`
 * for the MCP transport.
 *
 * Usage:
 *
 *   php artisan x402-mcp:list-tools "App\\Mcp\\Servers\\MyServer"
 *
 * **Resolution model:** the command reads the Server class' `$tools`
 * property *default* via reflection — it does not instantiate the Server.
 * This matches the laravel/mcp convention of declaring tools as
 * `protected array $tools = [...]`. Tools added dynamically in a
 * constructor or `boot()` override are not seen here.
 *
 * Each declared tool entry is resolved through the container so
 * `shouldRegister()` can be evaluated; tools that opt out of registration
 * are omitted, mirroring what `tools/list` would actually expose.
 */
final class ListToolsCommand extends Command
{
    protected $signature = 'x402-mcp:list-tools
        {server : Fully-qualified Laravel\\Mcp\\Server subclass to inspect}';

    protected $description = 'List MCP tools on a server, with x402 prices for gated tools.';

    public function handle(): int
    {
        $serverClass = (string) $this->argument('server');

        if (! class_exists($serverClass) || ! is_subclass_of($serverClass, Server::class)) {
            $this->components->error(sprintf('[%s] is not a Laravel\\Mcp\\Server subclass.', $serverClass));

            return self::FAILURE;
        }

        $tools = $this->resolveTools($serverClass);

        if ($tools === []) {
            $this->info(sprintf('No tools registered on %s.', $serverClass));

            return self::SUCCESS;
        }

        $rows = array_map($this->describeTool(...), $tools);

        $this->table(['Tool', 'Class', 'Amount', 'Asset', 'Network', 'PayTo'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Server>  $serverClass
     * @return list<Tool>
     */
    private function resolveTools(string $serverClass): array
    {
        $reflection = new ReflectionProperty($serverClass, 'tools');

        /** @var list<Tool|class-string<Tool>> $defaults */
        $defaults = $reflection->getDefaultValue() ?? [];

        $resolved = [];
        $container = Container::getInstance();

        foreach ($defaults as $entry) {
            $instance = $entry instanceof Tool
                ? $entry
                : (is_string($entry) && class_exists($entry) ? $container->make($entry) : null);

            if (! $instance instanceof Tool) {
                continue;
            }

            // Mirror ServerContext::tools() — a tool with shouldRegister() === false
            // never reaches `tools/list`, so the listing must hide it too.
            if (! $instance->eligibleForRegistration()) {
                continue;
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * @return array<int, string>
     */
    private function describeTool(Tool $tool): array
    {
        $price = X402Price::resolveFor($tool);

        if (! $price instanceof X402Price) {
            return [
                $tool->name(),
                $tool::class,
                '(free)',
                '',
                '',
                '',
            ];
        }

        return [
            $tool->name(),
            $tool::class,
            $price->amount,
            $price->asset,
            $price->network,
            $price->payTo ?? '(default)',
        ];
    }
}
