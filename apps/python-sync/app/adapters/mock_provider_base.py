from typing import Any

from app.adapters.base import BasePlatformAdapter


class MockProviderBase(BasePlatformAdapter):
    provider_name = "mock"
    supported_capabilities = {
        "create_product",
        "update_product",
        "sync_inventory",
        "pull_orders",
        "pull_refunds",
        "pull_listings",
        "query_result",
    }

    def supports(self, capability: str) -> bool:
        return capability in self.supported_capabilities

    def _build_external_id(self, payload: dict[str, Any]) -> str:
        biz_id = payload.get("biz_id") or payload.get("sku_code") or "biz"
        mode = payload.get("mock_mode", "success_immediate")
        return f"{self.provider_name}-{biz_id}-{mode}"

    def _handle_mode(self, payload: dict[str, Any], action: str) -> dict[str, Any]:
        mode = payload.get("mock_mode", "success_immediate")
        external_id = self._build_external_id(payload)

        if mode == "success_immediate":
            return self.make_response(
                success=True,
                accepted=False,
                final=True,
                code="SUCCESS",
                message=f"{self.provider_name} {action} success",
                external_id=external_id,
                raw_payload={"mode": mode, "provider": self.provider_name},
            )

        if mode == "fail_immediate":
            return self.make_response(
                success=False,
                accepted=False,
                final=True,
                code="FAIL_IMMEDIATE",
                message=f"{self.provider_name} {action} failed immediately",
                external_id=external_id,
                raw_payload={"mode": mode, "provider": self.provider_name},
            )

        if mode in {"accepted_then_success", "accepted_then_fail"}:
            return self.make_response(
                success=True,
                accepted=True,
                final=False,
                code="ACCEPTED",
                message=f"{self.provider_name} accepted request",
                external_id=external_id,
                raw_payload={"mode": mode, "provider": self.provider_name},
            )

        return self.make_response(
            success=False,
            accepted=False,
            final=True,
            code="UNKNOWN_MOCK_MODE",
            message=f"unsupported mock_mode: {mode}",
            external_id=external_id,
            raw_payload={"mode": mode, "provider": self.provider_name},
        )

    def create_product(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._handle_mode(payload, "create_product")

    def update_product(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._handle_mode(payload, "update_product")

    def sync_inventory(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._handle_mode(payload, "sync_inventory")

    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._handle_mode(payload, "pull_orders")

    def pull_refunds(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._handle_mode(payload, "pull_refunds")

    def pull_listings(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._handle_mode(payload, "pull_listings")

    def query_result(self, payload: dict[str, Any]) -> dict[str, Any]:
        mode = payload.get("mock_mode", "success_immediate")
        external_id = payload.get("external_id") or self._build_external_id(payload)

        if mode == "accepted_then_success":
            return self.make_response(
                success=True,
                accepted=False,
                final=True,
                code="SUCCESS",
                message=f"{self.provider_name} final success",
                external_id=external_id,
                raw_payload={"mode": mode, "provider": self.provider_name},
            )

        if mode == "accepted_then_fail":
            return self.make_response(
                success=False,
                accepted=False,
                final=True,
                code="FINAL_FAIL",
                message=f"{self.provider_name} final fail",
                external_id=external_id,
                raw_payload={"mode": mode, "provider": self.provider_name},
            )

        return self._handle_mode(payload, "query_result")
