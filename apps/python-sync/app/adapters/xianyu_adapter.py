from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from app.adapters.http_pull_kernel import HttpPullError, fetch_http_pull_records
from app.adapters.xianyu_mock import XianyuMockAdapter
from app.core.config import settings


class XianyuAdapter(XianyuMockAdapter):
    provider_name = "xianyu"

    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._pull_with_http(
            payload=payload,
            action="pull_orders",
            source_mode=settings.xianyu_orders_source_mode,
            endpoint=settings.xianyu_orders_endpoint,
            extra_params_json=settings.xianyu_orders_extra_params_json,
            normalize_record=self._normalize_order_record,
            id_keys=["external_order_id", "order_id", "id", "biz_order_id", "trade_no"],
            fallback=super().pull_orders,
        )

    def pull_refunds(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._pull_with_http(
            payload=payload,
            action="pull_refunds",
            source_mode=settings.xianyu_refunds_source_mode,
            endpoint=settings.xianyu_refunds_endpoint,
            extra_params_json=settings.xianyu_refunds_extra_params_json,
            normalize_record=self._normalize_refund_record,
            id_keys=["external_refund_id", "refund_id", "id"],
            fallback=super().pull_refunds,
        )

    def pull_listings(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._pull_with_http(
            payload=payload,
            action="pull_listings",
            source_mode=settings.xianyu_listings_source_mode,
            endpoint=settings.xianyu_listings_endpoint,
            extra_params_json=settings.xianyu_listings_extra_params_json,
            normalize_record=self._normalize_listing_record,
            id_keys=["external_listing_id", "listing_id", "id"],
            fallback=super().pull_listings,
        )

    def _pull_with_http(
        self,
        *,
        payload: dict[str, Any],
        action: str,
        source_mode: str,
        endpoint: str,
        extra_params_json: str,
        normalize_record,
        id_keys: list[str],
        fallback,
    ) -> dict[str, Any]:
        mode = (source_mode or "mock").strip().lower()
        if mode != "http":
            return fallback(payload)

        try:
            records, meta = fetch_http_pull_records(
                provider=self.provider_name,
                action=action,
                payload=payload,
                endpoint=endpoint,
                extra_params_json=extra_params_json,
                access_token=settings.xianyu_access_token,
                app_key=settings.xianyu_app_key,
                timeout_seconds=settings.xianyu_http_timeout_seconds,
                retry_attempts=settings.xianyu_http_retry_attempts,
                retry_backoff_seconds=settings.xianyu_http_retry_backoff_seconds,
                rate_limit_per_second=settings.xianyu_http_rate_limit_per_second,
                normalize_record=normalize_record,
            )
            return self.make_response(
                success=True,
                accepted=False,
                final=True,
                code="SUCCESS" if records else "SUCCESS_NO_DATA",
                message=f"xianyu {action} fetched {len(records)} records from http source",
                external_id=self._resolve_external_id(payload, records, id_keys),
                raw_payload={
                    "provider": self.provider_name,
                    "action": action,
                    "source": "http",
                    "records": records,
                    "meta": {**meta, "id_keys": id_keys},
                    "generated_at": datetime.now(timezone.utc).isoformat(),
                },
            )
        except HttpPullError as exc:
            message = f"xianyu {action} http source failed: {exc.message}"
            return self.make_response(
                success=False,
                accepted=False,
                final=True,
                code=exc.code,
                message=message[:500],
                external_id=self._build_external_id(payload),
                raw_payload={
                    "provider": self.provider_name,
                    "action": action,
                    "source": "http",
                    "error": exc.message[:1000],
                    "meta": {
                        "http_status": exc.http_status,
                        "retry_after_seconds": exc.retry_after_seconds,
                    },
                },
            )
        except Exception as exc:  # noqa: BLE001
            message = f"xianyu {action} http source failed: {exc}"
            return self.make_response(
                success=False,
                accepted=False,
                final=True,
                code="PULL_HTTP_REQUEST_FAILED",
                message=message[:500],
                external_id=self._build_external_id(payload),
                raw_payload={
                    "provider": self.provider_name,
                    "action": action,
                    "source": "http",
                    "error": str(exc)[:1000],
                },
            )

    def _normalize_order_record(self, record: dict[str, Any]) -> dict[str, Any] | None:
        external_order_id = self._pick_first(
            record,
            ["external_order_id", "order_id", "id", "biz_order_id", "trade_no"],
        )
        if external_order_id == "":
            return None

        amount_raw = self._pick_first(record, ["amount", "total_amount", "pay_amount", "price"])
        amount = self._parse_amount(amount_raw)

        return {
            "external_order_id": external_order_id,
            "order_type": self._pick_first(record, ["order_type", "biz_type"]) or "service",
            "subject": self._pick_first(record, ["subject", "title", "service_name", "name"]),
            "amount": amount,
            "currency": (self._pick_first(record, ["currency"]) or "CNY").upper(),
            "buyer_id": self._pick_first(record, ["buyer_id", "buyer_uid", "buyer_name", "user_id"]),
            "status": self._pick_first(record, ["status", "order_status", "state"]) or "pending",
            "order_created_at": self._pick_first(
                record,
                ["order_created_at", "created_at", "create_time", "order_time"],
            ),
            "source_record": record,
        }

    def _normalize_refund_record(self, record: dict[str, Any]) -> dict[str, Any] | None:
        external_refund_id = self._pick_first(record, ["external_refund_id", "refund_id", "id"])
        if external_refund_id == "":
            return None

        amount_raw = self._pick_first(record, ["amount", "refund_amount", "price"])
        amount = self._parse_amount(amount_raw)

        return {
            "external_refund_id": external_refund_id,
            "external_order_id": self._pick_first(
                record,
                ["external_order_id", "order_id", "biz_order_id", "trade_no"],
            ),
            "reason": self._pick_first(record, ["reason", "refund_reason", "remark"]),
            "amount": amount,
            "currency": (self._pick_first(record, ["currency"]) or "CNY").upper(),
            "status": self._pick_first(record, ["status", "refund_status", "state"]) or "pending",
            "refunded_at": self._pick_first(record, ["refunded_at", "refund_time", "updated_at"]),
            "source_record": record,
        }

    def _normalize_listing_record(self, record: dict[str, Any]) -> dict[str, Any] | None:
        external_listing_id = self._pick_first(record, ["external_listing_id", "listing_id", "id"])
        if external_listing_id == "":
            return None

        price_raw = self._pick_first(record, ["price", "amount", "list_price"])
        price = self._parse_amount(price_raw)

        return {
            "external_listing_id": external_listing_id,
            "title": self._pick_first(record, ["title", "subject", "name"]),
            "listing_type": self._pick_first(record, ["listing_type", "biz_type"]) or "service",
            "price": price,
            "currency": (self._pick_first(record, ["currency"]) or "CNY").upper(),
            "status": self._pick_first(record, ["status", "listing_status", "state"]) or "online",
            "source_record": record,
        }

    def _resolve_external_id(self, payload: dict[str, Any], records: list[dict[str, Any]], id_keys: list[str]) -> str:
        if records:
            for key in id_keys:
                first = records[0].get(key)
                if isinstance(first, str) and first != "":
                    return first
        return self._build_external_id(payload)

    @staticmethod
    def _pick_first(record: dict[str, Any], keys: list[str]) -> str:
        for key in keys:
            value = record.get(key)
            if value is None:
                continue
            text = str(value).strip()
            if text != "":
                return text
        return ""

    @staticmethod
    def _parse_amount(value: str) -> float:
        try:
            return round(max(0.0, float(value)), 2)
        except (TypeError, ValueError):
            return 0.0
