<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Concerns;

use Illuminate\Support\Collection;
use Laravel\Mcp\Server\Pagination\CursorPaginator;
use Laravel\Mcp\Server\Primitive;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use X402\Laravel\Mcp\Attributes\X402Price;

/**
 * Shared listing-side price advertisement for `X402ListTools`,
 * `X402ListResources`, and `X402ListPrompts`. Each of those handlers
 * differs only in the `Collection` it pulls from `ServerContext` and
 * the paginator label — the annotation pass and the cursor-paginate
 * call shape are identical.
 *
 * The walk happens BEFORE pagination: every primitive in the
 * collection is reflected for `#[X402Price]`, even those the cursor
 * will discard. Per-call cost is `N` reflection lookups (PHP caches
 * the underlying class entry), bounded by the registered primitive
 * count. Optimising to annotate post-slice would couple this code
 * to `CursorPaginator`'s internal cursor decoding; deferred.
 */
trait AdvertisesX402Price
{
    /**
     * @param  Collection<int, Primitive>  $items
     */
    private function advertisePrices(
        JsonRpcRequest $request,
        Collection $items,
        ServerContext $context,
        string $paginatorKey,
    ): JsonRpcResponse {
        $items->each($this->annotatePrice(...));

        $perPageRaw = $request->get('per_page');
        $perPage = is_int($perPageRaw) ? $perPageRaw : null;

        $paginator = new CursorPaginator(
            items: $items,
            perPage: $context->perPage($perPage),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate($paginatorKey));
    }

    private function annotatePrice(Primitive $primitive): void
    {
        $price = X402Price::resolveFor($primitive);

        if ($price instanceof X402Price) {
            $primitive->setMeta(X402Price::META_KEY, $price->toMetaArray());
        }
    }
}
