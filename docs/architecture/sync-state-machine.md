# Sync State Machine

## States
- `PENDING`: created and waiting for execution.
- `RUNNING`: worker started processing.
- `ACCEPTED`: platform accepted request but final result is pending.
- `SUCCESS`: final platform success confirmed.
- `FAIL`: final failure but can retry.
- `RETRYING`: retry requested and waiting for execution.
- `MANUAL_REVIEW`: exceeded retry threshold or requires manual handling.
- `CANCELLED`: cancelled by system/user.

## Key Rule
`ACCEPTED != SUCCESS`.

## Allowed Transitions
- `PENDING -> RUNNING | CANCELLED`
- `RETRYING -> RUNNING | CANCELLED`
- `RUNNING -> ACCEPTED | SUCCESS | FAIL | MANUAL_REVIEW`
- `ACCEPTED -> SUCCESS | FAIL | MANUAL_REVIEW | CANCELLED`
- `FAIL -> RETRYING | MANUAL_REVIEW | CANCELLED`
- `MANUAL_REVIEW -> RETRYING | CANCELLED`

## Retry Policy
- Every failed final response increases `retry_count`.
- If `retry_count >= max_retry_count`, task moves to `MANUAL_REVIEW`.
- Retry API only allows tasks in `FAIL` or `MANUAL_REVIEW`.

## Fake-Success Protection
- Provider immediate response with `accepted=true` only sets task to `ACCEPTED`.
- Only `final=true` and `success=true` can move task to `SUCCESS`.
- Polling receipts are persisted with phase `POLLING`.
