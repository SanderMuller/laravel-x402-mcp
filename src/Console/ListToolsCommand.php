<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Primitive;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use ReflectionProperty;
use X402\Laravel\Mcp\Attributes\X402Price;

/**
 * Inspect a Server class and list its tools, resources, and prompts —
 * marking which are gated by `#[X402Price]` and at what price. Mirrors
 * `x402:list-routes` for the MCP transport.
 *
 * Usage:
 *
 *   php artisan x402-mcp:list-tools "App\\Mcp\\Servers\\MyServer"
 *
 * **Resolution model:** the command reads the Server class' `$tools` /
 * `$resources` / `$prompts` property *defaults* via reflection — it does
 * not instantiate the Server. This matches the laravel/mcp convention of
 * declaring primitives as `protected array $tools = [...]` (etc.).
 * Primitives added dynamically in a constructor or `boot()` override are
 * not seen here.
 *
 * Each declared entry is resolved through the container so
 * `shouldRegister()` can be evaluated; primitives that opt out of
 * registration are omitted, mirroring what the corresponding
 * `tools/list` / `resources/list` / `prompts/list` endpoints
 * would actually expose.
 */
final class ListToolsCommand extends Command
{
    private const TYPE_TOOL = 'Tool';

    private const TYPE_RESOURCE = 'Resource';

    private const TYPE_PROMPT = 'Prompt';

    protected $signature = 'x402-mcp:list-tools
        {server : Fully-qualified Laravel\\Mcp\\Server subclass to inspect}';

    protected $description = 'List MCP tools, resources, and prompts on a server, with x402 prices for gated entries.';

    public function handle(): int
    {
        $serverClass = (string) $this->argument('server');

        if (! class_exists($serverClass) || ! is_subclass_of($serverClass, Server::class)) {
            $this->components->error(sprintf('[%s] is not a Laravel\\Mcp\\Server subclass.', $serverClass));

            return self::FAILURE;
        }

        $rows = [];

        foreach ($this->resolvePrimitives($serverClass, 'tools', Tool::class) as $primitive) {
            $rows[] = $this->describePrimitive(self::TYPE_TOOL, $primitive);
        }

        foreach ($this->resolvePrimitives($serverClass, 'resources', Resource::class) as $primitive) {
            $rows[] = $this->describePrimitive(self::TYPE_RESOURCE, $primitive);
        }

        foreach ($this->resolvePrimitives($serverClass, 'prompts', Prompt::class) as $primitive) {
            $rows[] = $this->describePrimitive(self::TYPE_PROMPT, $primitive);
        }

        if ($rows === []) {
            $this->info(sprintf('No tools, resources, or prompts registered on %s.', $serverClass));

            return self::SUCCESS;
        }

        $this->table(['Type', 'Name', 'Class', 'Amount', 'Asset', 'Network', 'PayTo'], $rows);

        return self::SUCCESS;
    }

    /**
     * @template TPrimitive of Primitive
     *
     * @param  class-string<Server>  $serverClass
     * @param  class-string<TPrimitive>  $expectedType
     * @return list<TPrimitive>
     */
    private function resolvePrimitives(string $serverClass, string $property, string $expectedType): array
    {
        if (! property_exists($serverClass, $property)) {
            return [];
        }

        $reflection = new ReflectionProperty($serverClass, $property);

        /** @var list<TPrimitive|class-string<TPrimitive>> $defaults */
        $defaults = $reflection->getDefaultValue() ?? [];

        $resolved = [];
        $container = Container::getInstance();

        foreach ($defaults as $entry) {
            $instance = $entry instanceof $expectedType
                ? $entry
                : (is_string($entry) && class_exists($entry) ? $container->make($entry) : null);

            if (! $instance instanceof $expectedType) {
                continue;
            }

            // Mirror ServerContext::*() — a primitive with shouldRegister() === false
            // never reaches the *list endpoint, so the listing must hide it too.
            if (! $instance->eligibleForRegistration()) {
                continue;
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function describePrimitive(string $type, Primitive $primitive): array
    {
        $price = X402Price::resolveFor($primitive);

        if (! $price instanceof X402Price) {
            return [
                $type,
                $primitive->name(),
                $primitive::class,
                '(free)',
                '',
                '',
                '',
            ];
        }

        return [
            $type,
            $primitive->name(),
            $primitive::class,
            $price->amount,
            $price->asset,
            $price->network,
            $price->payTo ?? '(default)',
        ];
    }
}
