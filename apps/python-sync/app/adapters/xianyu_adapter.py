from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from app.adapters.xianyu_mock import XianyuMockAdapter
from app.core.config import settings


class XianyuAdapter(XianyuMockAdapter):
    provider_name = "xianyu"

    def pull_orders(self, payload: dict[str, Any]) -> dict[str, Any]:
        source_mode = (settings.xianyu_orders_source_mode or "mock").strip().lower()
        if source_mode != "http":
            return super().pull_orders(payload)

        try:
            records, meta = self._fetch_order_records(payload)
            return self.make_response(
                success=True,
                accepted=False,
                final=True,
                code="SUCCESS" if records else "SUCCESS_NO_DATA",
                message=f"xianyu pull_orders fetched {len(records)} records from http source",
                external_id=self._resolve_external_id(payload, records),
                raw_payload={
                    "provider": self.provider_name,
                    "action": "pull_orders",
                    "source": "http",
                    "records": records,
                    "meta": meta,
                    "generated_at": datetime.now(timezone.utc).isoformat(),
                },
            )
        except Exception as exc:  # noqa: BLE001
            message = f"xianyu pull_orders http source failed: {exc}"
            return self.make_response(
                success=False,
                accepted=False,
                final=True,
                code="XY_PULL_ORDERS_ERROR",
                message=message[:500],
                external_id=self._build_external_id(payload),
                raw_payload={
                    "provider": self.provider_name,
                    "action": "pull_orders",
                    "source": "http",
                    "error": str(exc)[:1000],
                },
            )

    def _fetch_order_records(self, payload: dict[str, Any]) -> tuple[list[dict[str, Any]], dict[str, Any]]:
        endpoint = (settings.xianyu_orders_endpoint or "").strip()
        if endpoint == "":
            raise ValueError("XIANYU_ORDERS_ENDPOINT is empty")

        query = self._build_query_params(payload)
        request_url = endpoint
        if query:
            request_url = f"{endpoint}{'&' if '?' in endpoint else '?'}{urlencode(query)}"

        headers = {
            "Accept": "application/json",
        }
        access_token = (settings.xianyu_access_token or "").strip()
        if access_token != "":
            headers["Authorization"] = f"Bearer {access_token}"

        app_key = (settings.xianyu_app_key or "").strip()
        if app_key != "":
            headers["X-App-Key"] = app_key

        timeout = max(1.0, float(settings.xianyu_http_timeout_seconds))
        request = Request(url=request_url, headers=headers, method="GET")

        try:
            with urlopen(request, timeout=timeout) as response:
                http_status = int(getattr(response, "status", 200))
                body = response.read().decode("utf-8")
        except HTTPError as exc:
            raise RuntimeError(f"http error {exc.code}: {exc.reason}") from exc
        except URLError as exc:
            raise RuntimeError(f"network error: {exc.reason}") from exc

        if http_status >= 400:
            raise RuntimeError(f"http error status: {http_status}")

        try:
            decoded = json.loads(body)
        except json.JSONDecodeError as exc:
            raise RuntimeError("response is not valid json") from exc

        records = self._extract_records(decoded)
        normalized = []
        for record in records:
            item = self._normalize_order_record(record)
            if item is not None:
                normalized.append(item)

        meta = {
            "http_status": http_status,
            "request_url": request_url,
            "record_count": len(normalized),
        }
        return normalized, meta

    def _build_query_params(self, payload: dict[str, Any]) -> dict[str, str]:
        query: dict[str, str] = {}
        for key in ["since", "until", "cursor", "page", "page_size", "shop_id", "site_code", "biz_id"]:
            value = payload.get(key)
            if value is not None and value != "":
                query[key] = str(value)

        raw_extra = (settings.xianyu_orders_extra_params_json or "").strip()
        if raw_extra != "":
            try:
                parsed_extra = json.loads(raw_extra)
            except json.JSONDecodeError as exc:
                raise ValueError("XIANYU_ORDERS_EXTRA_PARAMS_JSON is not valid json object") from exc

            if not isinstance(parsed_extra, dict):
                raise ValueError("XIANYU_ORDERS_EXTRA_PARAMS_JSON must be json object")

            for key, value in parsed_extra.items():
                if value is not None and value != "" and key not in query:
                    query[str(key)] = str(value)

        return query

    def _extract_records(self, decoded: Any) -> list[dict[str, Any]]:
        if isinstance(decoded, list):
            return [item for item in decoded if isinstance(item, dict)]

        if not isinstance(decoded, dict):
            return []

        candidates = [
            decoded.get("records"),
            decoded.get("orders"),
            decoded.get("items"),
            decoded.get("list"),
            decoded.get("data", {}).get("records") if isinstance(decoded.get("data"), dict) else None,
            decoded.get("data", {}).get("orders") if isinstance(decoded.get("data"), dict) else None,
            decoded.get("data", {}).get("items") if isinstance(decoded.get("data"), dict) else None,
            decoded.get("data", {}).get("list") if isinstance(decoded.get("data"), dict) else None,
        ]

        for candidate in candidates:
            if isinstance(candidate, list):
                return [item for item in candidate if isinstance(item, dict)]

        return []

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

    def _resolve_external_id(self, payload: dict[str, Any], records: list[dict[str, Any]]) -> str:
        if records:
            first = records[0].get("external_order_id")
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
