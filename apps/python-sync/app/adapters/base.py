from abc import ABC, abstractmethod
from typing import Any


class BasePlatformAdapter(ABC):
    @abstractmethod
    def create_product(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def update_product(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def sync_inventory(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def pull_refunds(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def pull_listings(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def query_result(self, payload: dict[str, Any]) -> dict[str, Any]:
        pass

    @abstractmethod
    def supports(self, capability: str) -> bool:
        pass

    @staticmethod
    def make_response(
        *,
        success: bool,
        accepted: bool,
        final: bool,
        code: str,
        message: str,
        external_id: str | None = None,
        raw_payload: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        return {
            "success": success,
            "accepted": accepted,
            "final": final,
            "code": code,
            "message": message,
            "external_id": external_id or "",
            "raw_payload": raw_payload or {},
        }
