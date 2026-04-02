# PHP API (Laravel) - V1 Endpoints

Base URL: `http://localhost:8000/api`

Contract baseline for P0.5 is maintained in `docs/api/overview-phase-a.md`.

## Health
- `GET /health`

## Auth
- `POST /auth/send-sms-code`
- `POST /auth/verify-sms`
- `POST /auth/login`

## Shops
- `GET /shops`
- `POST /shops`
- `PUT /shops/{id}`

## Channel Accounts
- `GET /channel-accounts`
- `PATCH /channel-accounts/{id}/auth-status`
- `PATCH /channel-accounts/by-shop/{shopId}/auth-status`

## Products
- `GET /products/spu`
- `POST /products/spu`
- `GET /products/sku`
- `POST /products/sku`

## Service Orders
- `GET /service-orders`
- `POST /service-orders`
- `GET /service-orders/{id}`
- `PATCH /service-orders/{id}/status`

## Platform Mapping
- `GET /platform-product-mappings`
- `POST /platform-product-mappings`

## Sync Tasks
- `POST /sync-tasks`
- `GET /sync-tasks`
- `GET /sync-tasks/{id}`
- `POST /sync-tasks/{id}/run`
- `POST /sync-tasks/{id}/retry`
- `GET /sync-tasks/{id}/receipts`

`task_type` currently supported:

- `product_publish`
- `product_update`
- `inventory_sync`
- `order_pull`
- `refund_pull`
- `listing_pull`
- `service_pull`

## Webhooks

- `GET /webhooks/events`
- `POST /webhooks/events/{id}/retry`
- `POST /webhooks/{platform}/events`

Required headers:

- `X-Signature`: `hash_hmac('sha256', raw_body, WEBHOOK_SECRET_<PLATFORM> | WEBHOOK_SHARED_SECRET)`
- `X-Event-Id`: optional but recommended for stable idempotency key

Behavior:

- signature mismatch -> `401`
- duplicate processed event -> success with deduplicated result
- failed processing -> `500`, event status stored as `FAILED` and can be retried by provider callback
- failed events can be queried via `GET /webhooks/events?status=FAILED` and retried with `POST /webhooks/events/{id}/retry`

## Finance
- `GET /finance/receivables`
- `GET /finance/payments`
- `POST /finance/payments`
- `GET /finance/refunds`
- `POST /finance/refunds`
- `GET /finance/reconciliations`

## Legacy Contract Notes

- `PATCH /orders/{id}/status` (legacy generic order route) is still not implemented.
- `PUT /orders/{id}/status` is not an active contract.

## Unified Response
```json
{
  "success": true,
  "code": "OK",
  "message": "success",
  "data": {}
}
```

Error example:
```json
{
  "success": false,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "data": null
}
```
