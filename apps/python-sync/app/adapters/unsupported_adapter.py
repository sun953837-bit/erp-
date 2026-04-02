from __future__ import annotations

from typing import Any

from app.adapters.base import BasePlatformAdapter


class UnsupportedPlatformAdapter(BasePlatformAdapter):
    def __init__(self, platform_code: str) -> None:
        self.platform_code = (platform_code or "").lower()

    def create_product(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def update_product(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def sync_inventory(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def pull_refunds(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def pull_listings(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def pull_services(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def query_result(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._error_response()

    def supports(self, capability: str) -> bool:
        return False

    def _error_response(self) -> dict[str, Any]:
        return self.make_response(
            success=False,
            accepted=False,
            final=True,
            code="UNSUPPORTED_PLATFORM",
            message=f"unsupported platform adapter: {self.platform_code or 'unknown'}",
            external_id="",
            raw_payload={"platform_code": self.platform_code or "unknown"},
        )
