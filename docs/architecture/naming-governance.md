# Naming Governance (P0.5)

## Canonical Domain Names

- `products`
- `services`
- `channel_listings`
- `orders`
- `after_sales`
- `channel_accounts`
- `projects`
- `tickets`

## Legacy-to-Canonical Mapping

- `goods` -> `products`
- `service_catalog` -> `services`
- `platform_accounts` -> `channel_accounts`
- `unified_order` -> `orders`

## Current Codebase Reality

Current repository is still in sync-scaffold stage and mainly uses:

- `shops`
- `product_spu` / `product_sku`
- `platform_product_mappings`
- `sync_tasks` / `sync_receipt_logs`

Canonical names above are governance targets for next-stage closure and should be used in new docs/designs.

## Write-Path Freeze Policy

- New write paths should avoid introducing new legacy aliases.
- Existing legacy structures can remain for compatibility, but new APIs should prefer canonical naming.
- For fields/models not yet implemented in code, mark as planned; do not backfill fake contracts.

## Compatibility Rules

- Read-only compatibility is allowed for legacy names during migration period.
- Write operations must progressively converge to canonical names when affected modules are implemented.
- API docs must explicitly label each field/model as one of:
  - `implemented`
  - `compatibility(read-only)`
  - `planned`
