from typing import Any

from app.adapters.mock_provider_base import MockProviderBase


class ZbjMockAdapter(MockProviderBase):
    provider_name = "zbj"

    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_order_id": f"ZBJ-ORD-{task_no}-001",
                "order_type": "service",
                "subject": "技术开发服务",
                "amount": 1999.0,
                "currency": "CNY",
                "buyer_id": "zbj_buyer_001",
                "status": "confirmed",
            }
        ]
        return self._build_pull_response(action="pull_orders", payload=payload, records=records)

    def pull_refunds(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_refund_id": f"ZBJ-RFD-{task_no}-001",
                "external_order_id": f"ZBJ-ORD-{task_no}-001",
                "reason": "scope_change",
                "amount": 500.0,
                "currency": "CNY",
                "status": "approved",
            }
        ]
        return self._build_pull_response(action="pull_refunds", payload=payload, records=records)

    def pull_listings(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_listing_id": f"ZBJ-LST-{task_no}-001",
                "title": "定制化管理系统开发",
                "listing_type": "service",
                "price": 3999.0,
                "currency": "CNY",
                "status": "online",
            }
        ]
        return self._build_pull_response(action="pull_listings", payload=payload, records=records)

    def pull_services(self, payload: dict[str, Any]) -> dict[str, Any]:
        task_no = str(payload.get("task_no") or "task")
        records = [
            {
                "external_service_id": f"ZBJ-SVC-{task_no}-001",
                "name": "ERP 二开服务",
                "service_type": "delivery_project",
                "default_template": "project_template_default",
                "price": 5999.0,
                "currency": "CNY",
                "status": "active",
            },
            {
                "external_service_id": f"ZBJ-SVC-{task_no}-002",
                "name": "接口联调服务",
                "service_type": "ticket_support",
                "default_template": "ticket_template_default",
                "price": 899.0,
                "currency": "CNY",
                "status": "active",
            },
        ]
        return self._build_pull_response(action="pull_services", payload=payload, records=records)
