<?php

declare(strict_types=1);

/*
 * laravel/mcp 0.7 cross-version compatibility shim.
 *
 * 0.7 relocated several classes out of the Laravel\Mcp\Server\* sub-namespace
 * up to Laravel\Mcp\*:
 *
 *   Laravel\Mcp\Server\Transport\JsonRpcRequest   -> Laravel\Mcp\Transport\JsonRpcRequest
 *   Laravel\Mcp\Server\Transport\JsonRpcResponse  -> Laravel\Mcp\Transport\JsonRpcResponse
 *   Laravel\Mcp\Server\Exceptions\JsonRpcException -> Laravel\Mcp\Exceptions\JsonRpcException
 *
 * This bridge's method handlers extend laravel/mcp's own handlers and must
 * mirror their `handle()` signatures (which reference the transport DTOs), and
 * its source references JsonRpcException directly. Because the bridge supports
 * laravel/mcp ^0.6 || ^0.7, it is written against the 0.6 FQCNs; on 0.7 PHP
 * fatals at class-load time ("class Laravel\Mcp\Server\Transport\JsonRpcRequest
 * is not available") and runtime references resolve to missing classes.
 *
 * Aliasing the 0.7 classes back to the 0.6 names keeps a single set of
 * signatures and references valid on both versions. This must run at autoload
 * time (not in the service provider) because the handlers are loaded — and
 * signature-checked — during testing and static analysis, before any provider
 * boots. No-op on 0.6, where the original classes already exist.
 */
$relocations = [
    'Transport\\JsonRpcRequest',
    'Transport\\JsonRpcResponse',
    'Exceptions\\JsonRpcException',
];

foreach ($relocations as $suffix) {
    $current = 'Laravel\\Mcp\\' . $suffix;        // 0.7 location
    $legacy = 'Laravel\\Mcp\\Server\\' . $suffix; // 0.6 location

    if (class_exists($current) && ! class_exists($legacy)) {
        class_alias($current, $legacy);
    }
}
