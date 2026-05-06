# Security Policy

## Reporting a vulnerability

Open a private advisory on GitHub (`Security` → `Report a vulnerability`) or email `sander@hihaho.com`. Please do not file public issues for security bugs.

## Supported versions

Only the latest minor release receives security fixes. Pin to a version you can keep updated.

## MCP-specific concerns

This bridge gates `tools/call` JSON-RPC requests behind x402 payment. AI agents calling your MCP server will see the price advertised in `tools/list` and must include a signed `PAYMENT-SIGNATURE` header to invoke a paid tool.

### Stdio transport is unprotected

The Streamable HTTP transport is the only transport that can carry payment headers. **On stdio, paid tools are silently treated as free** because there's no HTTP envelope to gate. If you ship paid tools, run only the HTTP transport in production. Document this clearly to operators if your package exposes both.

### `tools/list` is always free

By design, `tools/list` is not gated — agents need to discover prices before paying. The annotation includes the recipient address (`payTo`), the network, and the asset. Treat this as public information.

### Free + paid tools coexist

A tool without `#[X402Price]` is free. Mixing paid and free tools in one server is fine; the middleware short-circuits on tools that don't carry the attribute. Audit your `#[X402Price]` annotations the same way you'd audit a route's `auth` middleware — a missing annotation means a free tool.

### Replay + key custody

All concerns from `laravel-x402` apply transitively (private key in `X402_PRIVATE_KEY`, atomic nonce store, recipient address). See [`laravel-x402/SECURITY.md`](https://github.com/sandermuller/laravel-x402/blob/main/SECURITY.md) for the full set.

## Facilitator trust

Same model as the underlying `laravel-x402` package — Coinbase-hosted by default, swap via config.
