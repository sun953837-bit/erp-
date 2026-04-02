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

## Implemented Python Sync Endpoint

- `GET /internal/health`

## Contract Decisions (Conflict Resolution)

### Order status update API

- Current status: not implemented.
- Canonical reservation for future implementation: `PATCH /orders/{id}/status`.
- `PUT /orders/{id}/status` is not documented as active in Stage-1.

### `finance/receivables`

- Current status: not implemented in this repository.
- Any document mentioning `finance/receivables` must be treated as future scope until API and schema are added.

## Stage-1 Boundary

- Current APIs focus on sync scaffolding and product/shop mapping flow.
- Service-order delivery-finance closed loop APIs are not in current implementation and are tracked as P1 tasks.
