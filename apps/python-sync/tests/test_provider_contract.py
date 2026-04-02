from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path
from urllib.error import URLError
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from app.adapters.xianyu_adapter import XianyuAdapter  # noqa: E402
from app.adapters.zbj_adapter import ZbjAdapter  # noqa: E402
from app.core.config import settings  # noqa: E402


class _FakeResponse:
    def __init__(self, *, body: dict, status: int = 200, headers: dict[str, str] | None = None) -> None:
        self._encoded = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.status = status
        self.headers = headers or {}

    def read(self) -> bytes:
        return self._encoded

    def __enter__(self) -> "_FakeResponse":
        return self

    def __exit__(self, exc_type, exc, tb) -> bool:  # noqa: ANN001
        return False


class ProviderContractTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self._snapshot = {
            "xianyu_orders_source_mode": settings.xianyu_orders_source_mode,
            "xianyu_orders_endpoint": settings.xianyu_orders_endpoint,
            "xianyu_http_retry_attempts": settings.xianyu_http_retry_attempts,
            "xianyu_http_retry_backoff_seconds": settings.xianyu_http_retry_backoff_seconds,
            "xianyu_http_rate_limit_per_second": settings.xianyu_http_rate_limit_per_second,
            "zbj_orders_source_mode": settings.zbj_orders_source_mode,
            "zbj_orders_endpoint": settings.zbj_orders_endpoint,
            "zbj_http_retry_attempts": settings.zbj_http_retry_attempts,
            "zbj_http_retry_backoff_seconds": settings.zbj_http_retry_backoff_seconds,
            "zbj_http_rate_limit_per_second": settings.zbj_http_rate_limit_per_second,
        }

    def tearDown(self) -> None:
        for key, value in self._snapshot.items():
            setattr(settings, key, value)

    def test_xianyu_pull_orders_http_contract_contains_pagination_and_incremental(self) -> None:
        settings.xianyu_orders_source_mode = "http"
        settings.xianyu_orders_endpoint = "https://example.test/xianyu/orders"
        settings.xianyu_http_retry_attempts = 0
        settings.xianyu_http_retry_backoff_seconds = 0.0
        settings.xianyu_http_rate_limit_per_second = 1000.0

        response_body = {
            "records": [
                {
                    "order_id": "XY1001",
                    "title": "店铺代运营咨询",
                    "amount": "699.00",
                    "currency": "CNY",
                    "buyer_id": "buyer-1",
                    "status": "paid",
                }
            ],
            "pagination": {
                "page": 1,
                "page_size": 50,
                "next_cursor": "cursor-2",
                "has_more": True,
            },
            "server_last_pulled_at": "2026-04-02T09:30:00Z",
        }
        with patch("app.adapters.http_pull_kernel.urlopen", return_value=_FakeResponse(body=response_body)):
            adapter = XianyuAdapter()
            result = adapter.pull_orders({
                "page": 1,
                "page_size": 50,
                "last_pulled_at": "2026-04-01T00:00:00Z",
            })

        self.assertTrue(bool(result["success"]))
        self.assertEqual("SUCCESS", result["code"])
        self.assertTrue(isinstance(result["raw_payload"], dict))

        payload = result["raw_payload"]
        self.assertEqual("xianyu", payload.get("provider"))
        self.assertEqual("pull_orders", payload.get("action"))

        records = payload.get("records")
        self.assertTrue(isinstance(records, list))
        self.assertEqual("XY1001", records[0]["external_order_id"])

        meta = payload.get("meta") or {}
        paging = meta.get("pagination") or {}
        incremental = meta.get("incremental") or {}
        self.assertEqual("cursor-2", paging.get("next_cursor"))
        self.assertEqual(True, paging.get("has_more"))
        self.assertEqual("2026-04-01T00:00:00Z", incremental.get("request_last_pulled_at"))
        self.assertEqual("2026-04-02T09:30:00Z", incremental.get("server_last_pulled_at"))

    def test_zbj_pull_orders_missing_endpoint_returns_standard_config_error(self) -> None:
        settings.zbj_orders_source_mode = "http"
        settings.zbj_orders_endpoint = ""

        adapter = ZbjAdapter()
        result = adapter.pull_orders({"page": 1})
        self.assertFalse(bool(result["success"]))
        self.assertEqual("PULL_SOURCE_CONFIG_ERROR", result["code"])

    def test_zbj_pull_orders_retry_exhausted_uses_standard_error_code(self) -> None:
        settings.zbj_orders_source_mode = "http"
        settings.zbj_orders_endpoint = "https://example.test/zbj/orders"
        settings.zbj_http_retry_attempts = 1
        settings.zbj_http_retry_backoff_seconds = 0.0
        settings.zbj_http_rate_limit_per_second = 1000.0

        with patch("app.adapters.http_pull_kernel.urlopen", side_effect=URLError("boom")):
            adapter = ZbjAdapter()
            result = adapter.pull_orders({"page": 1, "page_size": 20})

        self.assertFalse(bool(result["success"]))
        self.assertEqual("PULL_HTTP_RETRY_EXHAUSTED", result["code"])

    def test_mock_mode_keeps_contract_shape(self) -> None:
        settings.xianyu_orders_source_mode = "mock"
        settings.zbj_orders_source_mode = "mock"

        xianyu_result = XianyuAdapter().pull_orders({"task_no": "T1"})
        zbj_result = ZbjAdapter().pull_orders({"task_no": "T2"})

        for result in [xianyu_result, zbj_result]:
            self.assertIn("success", result)
            self.assertIn("accepted", result)
            self.assertIn("final", result)
            self.assertIn("code", result)
            self.assertIn("message", result)
            self.assertIn("external_id", result)
            self.assertIn("raw_payload", result)


if __name__ == "__main__":
    unittest.main()
