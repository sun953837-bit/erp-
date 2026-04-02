# Secrets and Webhook Security Baseline

## Secret Injection Policy

Use the same key names across local `.env`, CI secret store, and server runtime:

- webhook:
  - `WEBHOOK_SHARED_SECRET`
  - `WEBHOOK_SECRET_XIANYU`
  - `WEBHOOK_SECRET_ZBJ`
- sync provider auth:
  - `XIANYU_ACCESS_TOKEN`
  - `XIANYU_APP_KEY`
  - `ZBJ_ACCESS_TOKEN`
  - `ZBJ_APP_KEY`
- internal integration:
  - `CHANNEL_ACCOUNT_SYNC_INTERNAL_TOKEN`

## Environment Consistency

- Local dev:
  - use `.env` based on `.env.example`
- CI:
  - inject same keys from CI secret manager
- Server:
  - inject same keys via process env or secret volume

Do not hardcode keys in source, seeders, or docs examples.

## Webhook Security Window

- Timestamp enforcement:
  - `WEBHOOK_REQUIRE_TIMESTAMP=true`
  - `X-Timestamp` (or payload timestamp field) must be present
- Allowed drift window:
  - `WEBHOOK_ALLOWED_DRIFT_SECONDS` (default 300s)
- Signature:
  - `hash_hmac('sha256', raw_body, WEBHOOK_SECRET_<PLATFORM> | WEBHOOK_SHARED_SECRET)`

Requests outside time window are rejected with `401`.

## Replay and Compensation

- Idempotency key priority:
  1. `X-Event-Id` header
  2. payload ids (`event_id`, `idempotency_key`, `message_id`, `request_id`)
  3. hash fallback (`platform + raw_body`)
- Duplicate processed event is deduplicated.
- Failed event can be replayed by:
  - `POST /api/webhooks/events/{id}/retry`

## Log Desensitization and Payload Clipping

- PHP-side masking:
  - `SensitiveDataMasker::maskArray` masks keys containing:
    - `secret`, `token`, `password`, `signature`, `authorization`, `app_key`, `client_secret`, `access_key`, `phone`
- Payload clipping:
  - webhook persistence/audit clip by `WEBHOOK_MAX_PAYLOAD_BYTES`
  - webhook error message length clip by `WEBHOOK_MAX_ERROR_MESSAGE_LENGTH`
  - python provider error payload clip by `PROVIDER_ERROR_PAYLOAD_MAX_BYTES`

## Pre-Prod Checklist

1. Ensure all production keys are injected only from secret manager.
2. Ensure `WEBHOOK_REQUIRE_TIMESTAMP=true`.
3. Run webhook replay tests and verify dedupe + retry paths.
4. Confirm logs contain masked values for secret-like keys.
