from __future__ import annotations

import json
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin
from urllib.request import Request, urlopen

from app.core.config import settings
from app.models.sync_task import SyncTask


AUTH_EXPIRED_CODES = {
    "AUTH_EXPIRED",
    "TOKEN_INVALID",
    "TOKEN_EXPIRED",
}

AUTH_REVOKED_CODES = {
    "AUTH_REVOKED",
    "PERMISSION_DENIED",
    "ACCOUNT_DISABLED",
}


def maybe_write_back_auth_status(task: SyncTask, result: dict[str, Any]) -> dict[str, Any] | None:
    if not settings.channel_account_sync_enabled:
        return None

    status = _resolve_target_status(result)
    if status is None:
        return None

    base_url = (settings.php_api_base_url or "").strip()
    if base_url == "":
        return {
            "triggered": False,
            "success": False,
            "status": status,
            "reason": "php_api_base_url_empty",
        }

    endpoint = urljoin(base_url.rstrip("/") + "/", f"api/channel-accounts/by-shop/{task.shop_id}/auth-status")
    payload = {
        "status": status,
        "is_configured": False,
    }

    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
    }
    internal_token = (settings.channel_account_sync_internal_token or "").strip()
    if internal_token != "":
        headers["X-Internal-Token"] = internal_token

    timeout = max(1.0, float(settings.channel_account_sync_timeout_seconds))
    request = Request(
        url=endpoint,
        data=json.dumps(payload).encode("utf-8"),
        headers=headers,
        method="PATCH",
    )

    try:
        with urlopen(request, timeout=timeout) as response:
            http_status = int(getattr(response, "status", 200))
            body = response.read().decode("utf-8")
            parsed = _decode_json(body)
            return {
                "triggered": True,
                "success": 200 <= http_status < 300,
                "status": status,
                "http_status": http_status,
                "endpoint": endpoint,
                "response": parsed,
            }
    except HTTPError as exc:
        return {
            "triggered": True,
            "success": False,
            "status": status,
            "endpoint": endpoint,
            "http_status": exc.code,
            "error": str(exc.reason),
        }
    except URLError as exc:
        return {
            "triggered": True,
            "success": False,
            "status": status,
            "endpoint": endpoint,
            "error": f"network error: {exc.reason}",
        }
    except Exception as exc:  # noqa: BLE001
        return {
            "triggered": True,
            "success": False,
            "status": status,
            "endpoint": endpoint,
            "error": str(exc),
        }


def _resolve_target_status(result: dict[str, Any]) -> str | None:
    code = str(result.get("code") or "").upper()
    message = str(result.get("message") or "").lower()

    if code in AUTH_EXPIRED_CODES:
        return "EXPIRED"
    if code in AUTH_REVOKED_CODES:
        return "REVOKED"

    if "token expired" in message or "auth expired" in message:
        return "EXPIRED"
    if "permission denied" in message or "auth revoked" in message:
        return "REVOKED"

    return None


def _decode_json(raw: str) -> Any:
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return {"raw": raw}
