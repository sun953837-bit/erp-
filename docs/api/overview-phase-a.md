# API Overview (P0.5 Contract Baseline)

This document aligns API docs with the actual implementation for the current Stage-1 closure.

## Base URLs

- PHP API: `http://localhost:8000/api`
- Python Sync internal API: `http://localhost:8100/internal`

## Implemented PHP API Endpoints

### Health

- `GET /health`

### Auth

- `POST /auth/send-sms-code`
- `POST /auth/verify-sms`
- `POST /auth/login`

### Shops

- `GET /shops`
- `POST /shops`
- `PUT /shops/{id}`

### Channel Accounts

- `GET /channel-accounts`
- `PATCH /channel-accounts/{id}/auth-status`
- `PATCH /channel-accounts/by-shop/{shopId}/auth-status`

### Products

- `GET /products/spu`
- `POST /products/spu`
- `GET /products/sku`
- `POST /products/sku`

### Service Orders

- `GET /service-orders`
- `POST /service-orders`
- `GET /service-orders/{id}`
- `PATCH /service-orders/{id}/status`

### Platform Mapping

- `GET /platform-product-mappings`
- `POST /platform-product-mappings`

### Sync Tasks

- `POST /sync-tasks`
- `GET /sync-tasks`
- `GET /sync-tasks/{id}`
- `POST /sync-tasks/{id}/run`
- `POST /sync-tasks/{id}/retry`
- `GET /sync-tasks/{id}/receipts`

### Webhooks

- `GET /webhooks/events`
- `POST /webhooks/events/{id}/retry`
- `POST /webhooks/{platform}/events`
  - Signature validation enabled (`X-Signature`, HMAC-SHA256)
  - Idempotency enabled (`X-Event-Id` or payload key fallback)
  - Failed processing is persisted for retry callbacks

### Finance

- `GET /finance/receivables`
- `GET /finance/payments`
- `POST /finance/payments`
- `GET /finance/refunds`
- `POST /finance/refunds`
- `GET /finance/reconciliations`

## Implemented Python Sync Endpoint

- `GET /internal/health`

## Contract Decisions (Conflict Resolution)

### Order status update API

- Legacy generic route `PATCH /orders/{id}/status` is not implemented.
- Service-order status update is implemented as `PATCH /service-orders/{id}/status`.
- `PUT /orders/{id}/status` is not documented as active in Stage-1.

### `finance/receivables`

- Current status: implemented as `GET /finance/receivables`.
- Receivable auto-creation is triggered on service-order confirmation.

## Stage-1 Boundary

- Current APIs focus on sync scaffolding and product/shop mapping flow.
- A minimal P1 subset is implemented: service-order status flow, auto receivable, payment/refund/reconciliation linkage.
