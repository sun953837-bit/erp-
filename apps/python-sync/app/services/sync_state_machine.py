from dataclasses import dataclass
from enum import Enum


class SyncStatus(str, Enum):
    PENDING = "PENDING"
    RUNNING = "RUNNING"
    ACCEPTED = "ACCEPTED"
    SUCCESS = "SUCCESS"
    FAIL = "FAIL"
    RETRYING = "RETRYING"
    MANUAL_REVIEW = "MANUAL_REVIEW"
    CANCELLED = "CANCELLED"


class InvalidStatusTransitionError(ValueError):
    pass


@dataclass(frozen=True)
class TransitionRule:
    from_status: SyncStatus
    to_statuses: set[SyncStatus]


class SyncStateMachine:
    _rules = {
        SyncStatus.PENDING: {SyncStatus.RUNNING, SyncStatus.CANCELLED},
        SyncStatus.RETRYING: {SyncStatus.RUNNING, SyncStatus.CANCELLED},
        SyncStatus.RUNNING: {
            SyncStatus.ACCEPTED,
            SyncStatus.SUCCESS,
            SyncStatus.FAIL,
            SyncStatus.MANUAL_REVIEW,
        },
        SyncStatus.ACCEPTED: {
            SyncStatus.SUCCESS,
            SyncStatus.FAIL,
            SyncStatus.MANUAL_REVIEW,
            SyncStatus.CANCELLED,
        },
        SyncStatus.FAIL: {
            SyncStatus.RETRYING,
            SyncStatus.MANUAL_REVIEW,
            SyncStatus.CANCELLED,
        },
        SyncStatus.MANUAL_REVIEW: {
            SyncStatus.RETRYING,
            SyncStatus.CANCELLED,
        },
        SyncStatus.SUCCESS: set(),
        SyncStatus.CANCELLED: set(),
    }

    @classmethod
    def validate_transition(cls, current: str, target: str) -> None:
        from_status = SyncStatus(current)
        to_status = SyncStatus(target)
        allowed = cls._rules.get(from_status, set())
        if to_status not in allowed:
            raise InvalidStatusTransitionError(
                f"Invalid transition: {from_status.value} -> {to_status.value}"
            )

    @staticmethod
    def is_success(status: str) -> bool:
        return status == SyncStatus.SUCCESS.value

    @staticmethod
    def resolve_failure_target(retry_count: int, max_retry_count: int) -> SyncStatus:
        if retry_count >= max_retry_count:
            return SyncStatus.MANUAL_REVIEW
        return SyncStatus.FAIL

    @staticmethod
    def can_retry(status: str) -> bool:
        return status in {SyncStatus.FAIL.value, SyncStatus.MANUAL_REVIEW.value}
