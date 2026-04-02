from typing import Any

from app.adapters.mock_provider_base import MockProviderBase


class XianyuMockAdapter(MockProviderBase):
    provider_name = "xianyu"

    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_order_id": f"XY-ORD-{task_no}-001",
                "order_type": "service",
                "subject": "账号诊断服务",
                "amount": 399.0,
                "currency": "CNY",
                "buyer_id": "xy_buyer_001",
                "status": "confirmed",
            },
            {
                "external_order_id": f"XY-ORD-{task_no}-002",
                "order_type": "service",
                "subject": "店铺代运营咨询",
                "amount": 699.0,
                "currency": "CNY",
                "buyer_id": "xy_buyer_002",
                "status": "pending",
            },
        ]
        return self._build_pull_response(action="pull_orders", payload=payload, records=records)

    def pull_refunds(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_refund_id": f"XY-RFD-{task_no}-001",
                "external_order_id": f"XY-ORD-{task_no}-001",
                "reason": "service_not_started",
                "amount": 199.0,
                "currency": "CNY",
                "status": "processing",
            }
        ]
        return self._build_pull_response(action="pull_refunds", payload=payload, records=records)

    def pull_listings(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_listing_id": f"XY-LST-{task_no}-001",
                "title": "闲鱼店铺运营陪跑",
                "listing_type": "service",
                "price": 499.0,
                "currency": "CNY",
                "status": "online",
            },
            {
                "external_listing_id": f"XY-LST-{task_no}-002",
                "title": "闲鱼商品主图优化",
                "listing_type": "service",
                "price": 299.0,
                "currency": "CNY",
                "status": "online",
            },
        ]
        return self._build_pull_response(action="pull_listings", payload=payload, records=records)
