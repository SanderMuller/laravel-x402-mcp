<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server;

use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Support\PriceMeta;

/**
 * Inspects every registered tool at boot, finds those with #[X402Price] but
 * without the HasX402Price trait applied, and either:
 *  - logs a warning so the developer adds the trait, or
 *  - (future) wraps the tool's toArray() output via the Registrar.
 *
 * The current strategy relies on the trait being present. This helper exists
 * so we can move the meta-injection into a tool decorator if laravel/mcp
 * grows a ToolDecorator extension point — keeps the contract stable for
 * consumers.
 */
final class MetaInjector
{
    /**
     * @return list<string> Tool class names that declared #[X402Price] but did NOT use the HasX402Price trait.
     */
    public static function findUntraitedTools(Registrar $registrar): array
    {
        $missing = [];

        foreach (self::tools($registrar) as $tool) {
            $reflection = new ReflectionClass($tool);

            if ($reflection->getAttributes(X402Price::class) === []) {
                continue;
            }

            $hasTrait = false;
            foreach ($reflection->getTraitNames() as $trait) {
                if (str_ends_with($trait, 'HasX402Price')) {
                    $hasTrait = true;
                    break;
                }
            }

            if (! $hasTrait) {
                $missing[] = $reflection->getName();
            }
        }

        return $missing;
    }

    /**
     * @return iterable<Tool>
     */
    private static function tools(Registrar $registrar): iterable
    {
        // laravel/mcp's Registrar API is still stabilising. Whichever accessor
        // it ships, we reach for it once here so the rest of the bridge stays
        // decoupled from the exact method name.
        if (method_exists($registrar, 'tools')) {
            /** @var iterable<Tool> $tools */
            $tools = $registrar->tools();

            return $tools;
        }

        if (method_exists($registrar, 'getTools')) {
            /** @var iterable<Tool> $tools */
            $tools = $registrar->getTools();

            return $tools;
        }

        return [];
    }

    /**
     * Reference helper — kept here so users can call it manually if they want
     * to assert the meta block in a feature test against a Tool instance.
     *
     * @return array<string, string>
     */
    public static function metaFor(Tool $tool): array
    {
        $attrs = (new ReflectionClass($tool))->getAttributes(X402Price::class);

        if ($attrs === []) {
            return [];
        }

        /** @var X402Price $price */
        $price = $attrs[0]->newInstance();

        return PriceMeta::build($price);
    }
}
