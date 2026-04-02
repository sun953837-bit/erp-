from __future__ import annotations

import json
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

STANDARD_PULL_CODES = {
    "config_error": "PULL_SOURCE_CONFIG_ERROR",
    "http_error": "PULL_HTTP_REQUEST_FAILED",
    "invalid_response": "PULL_HTTP_INVALID_RESPONSE",
    "rate_limited": "PULL_HTTP_RATE_LIMITED",
    "retry_exhausted": "PULL_HTTP_RETRY_EXHAUSTED",
}

_LAST_REQUEST_TS: dict[str, float] = {}


@dataclass
class HttpPullError(Exception):
    code: str
    message: str
    retriable: bool = False
    http_status: int | None = None
    retry_after_seconds: float | None = None


def fetch_http_pull_records(
    *,
    provider: str,
    action: str,
    payload: dict[str, Any],
    endpoint: str,
    extra_params_json: str,
    access_token: str,
    app_key: str,
    timeout_seconds: float,
    retry_attempts: int,
    retry_backoff_seconds: float,
    rate_limit_per_second: float,
    normalize_record: Callable[[dict[str, Any]], dict[str, Any] | None],
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    endpoint_value = (endpoint or "").strip()
    if endpoint_value == "":
        raise HttpPullError(
            code=STANDARD_PULL_CODES["config_error"],
            message="pull endpoint is empty",
            retriable=False,
        )

    query, request_paging, request_incremental = _build_query_params(payload, extra_params_json)
    request_url = endpoint_value
    if query:
        request_url = f"{endpoint_value}{'&' if '?' in endpoint_value else '?'}{urlencode(query)}"

    headers = {"Accept": "application/json"}
    access_token_value = (access_token or "").strip()
    if access_token_value != "":
        headers["Authorization"] = f"Bearer {access_token_value}"
    app_key_value = (app_key or "").strip()
    if app_key_value != "":
        headers["X-App-Key"] = app_key_value

    timeout = max(1.0, float(timeout_seconds))
    max_retry_attempts = max(0, int(retry_attempts))
    total_attempts = max_retry_attempts + 1
    base_backoff = max(0.0, float(retry_backoff_seconds))
    rate_limit = max(0.0, float(rate_limit_per_second))

    last_error: HttpPullError | None = None
    for attempt_index in range(total_attempts):
        _apply_rate_limit(provider, rate_limit)
        request = Request(url=request_url, headers=headers, method="GET")

        try:
            with urlopen(request, timeout=timeout) as response:
                http_status = int(getattr(response, "status", 200))
                body = response.read().decode("utf-8")
                response_headers = dict(response.headers.items())
        except HTTPError as exc:
            retry_after = _parse_retry_after(getattr(exc, "headers", None))
            message = f"http error {exc.code}: {exc.reason}"
            code = STANDARD_PULL_CODES["http_error"]
            retriable = exc.code >= 500
            if exc.code == 429:
                code = STANDARD_PULL_CODES["rate_limited"]
                retriable = True
            last_error = HttpPullError(
                code=code,
                message=message,
                retriable=retriable,
                http_status=exc.code,
                retry_after_seconds=retry_after,
            )
        except URLError as exc:
            last_error = HttpPullError(
                code=STANDARD_PULL_CODES["http_error"],
                message=f"network error: {exc.reason}",
                retriable=True,
            )
        else:
            if http_status == 429:
                last_error = HttpPullError(
                    code=STANDARD_PULL_CODES["rate_limited"],
                    message="http rate limited (429)",
                    retriable=True,
                    http_status=http_status,
                    retry_after_seconds=_parse_retry_after(response_headers),
                )
            elif http_status >= 500:
                last_error = HttpPullError(
                    code=STANDARD_PULL_CODES["http_error"],
                    message=f"http server error status: {http_status}",
                    retriable=True,
                    http_status=http_status,
                )
            elif http_status >= 400:
                raise HttpPullError(
                    code=STANDARD_PULL_CODES["http_error"],
                    message=f"http error status: {http_status}",
                    retriable=False,
                    http_status=http_status,
                )
            else:
                try:
                    decoded = json.loads(body)
                except json.JSONDecodeError as exc:
                    raise HttpPullError(
                        code=STANDARD_PULL_CODES["invalid_response"],
                        message="response is not valid json",
                        retriable=False,
                    ) from exc

                records = _extract_records(decoded)
                normalized: list[dict[str, Any]] = []
                for record in records:
                    item = normalize_record(record)
                    if item is not None:
                        normalized.append(item)

                response_paging = _extract_pagination(decoded, request_paging)
                response_incremental = _extract_incremental(decoded, request_incremental)
                meta = {
                    "provider": provider,
                    "action": action,
                    "source": "http",
                    "http_status": http_status,
                    "request_url": request_url,
                    "record_count": len(normalized),
                    "attempt": attempt_index + 1,
                    "retry_attempts": max(0, attempt_index),
                    "pagination": response_paging,
                    "incremental": response_incremental,
                    "queried_at": datetime.now(timezone.utc).isoformat(),
                }
                return normalized, meta

        if last_error is None:
            continue
        if not last_error.retriable:
            raise last_error
        if attempt_index >= total_attempts - 1:
            raise HttpPullError(
                code=STANDARD_PULL_CODES["retry_exhausted"],
                message=f"retry exhausted: {last_error.message}",
                retriable=False,
                http_status=last_error.http_status,
                retry_after_seconds=last_error.retry_after_seconds,
            ) from last_error

        sleep_seconds = _resolve_retry_sleep(
            retry_after_seconds=last_error.retry_after_seconds,
            base_backoff_seconds=base_backoff,
            attempt_index=attempt_index,
        )
        if sleep_seconds > 0:
            time.sleep(sleep_seconds)

    raise HttpPullError(
        code=STANDARD_PULL_CODES["retry_exhausted"],
        message="retry exhausted due to unknown reason",
        retriable=False,
    )


def _build_query_params(payload: dict[str, Any], raw_extra: str) -> tuple[dict[str, str], dict[str, Any], dict[str, Any]]:
    query: dict[str, str] = {}

    pagination_payload = payload.get("pagination")
    pagination_data = pagination_payload if isinstance(pagination_payload, dict) else {}
    incremental_payload = payload.get("incremental")
    incremental_data = incremental_payload if isinstance(incremental_payload, dict) else {}

    since = _pick_value(payload, incremental_data, ["since", "last_pulled_at"])
    cursor = _pick_value(payload, incremental_data, ["cursor", "incremental_cursor"])
    page = _pick_value(payload, pagination_data, ["page"])
    page_size = _pick_value(payload, pagination_data, ["page_size"])

    if since is not None and since != "":
        query["since"] = str(since)
    if cursor is not None and cursor != "":
        query["cursor"] = str(cursor)
    if page is not None and page != "":
        query["page"] = str(page)
    if page_size is not None and page_size != "":
        query["page_size"] = str(page_size)

    for key in ["until", "shop_id", "site_code", "biz_id", "last_pulled_at"]:
        value = payload.get(key)
        if value is not None and value != "":
            query[key] = str(value)

    extra_text = (raw_extra or "").strip()
    if extra_text != "":
        try:
            parsed_extra = json.loads(extra_text)
        except json.JSONDecodeError as exc:
            raise HttpPullError(
                code=STANDARD_PULL_CODES["config_error"],
                message="extra params json is not valid json object",
                retriable=False,
            ) from exc

        if not isinstance(parsed_extra, dict):
            raise HttpPullError(
                code=STANDARD_PULL_CODES["config_error"],
                message="extra params json must be json object",
                retriable=False,
            )

        for key, value in parsed_extra.items():
            key_text = str(key)
            if value is not None and value != "" and key_text not in query:
                query[key_text] = str(value)

    request_paging = {
        "mode": "cursor" if "cursor" in query else "page",
        "page": query.get("page"),
        "page_size": query.get("page_size"),
        "cursor": query.get("cursor"),
    }
    request_incremental = {
        "since": query.get("since"),
        "last_pulled_at": query.get("last_pulled_at"),
        "incremental_cursor": query.get("cursor"),
    }
    return query, request_paging, request_incremental


def _pick_value(payload: dict[str, Any], nested: dict[str, Any], keys: list[str]) -> Any:
    for key in keys:
        nested_value = nested.get(key)
        if nested_value is not None and nested_value != "":
            return nested_value
    for key in keys:
        top_value = payload.get(key)
        if top_value is not None and top_value != "":
            return top_value
    return None


def _extract_records(decoded: Any) -> list[dict[str, Any]]:
    if isinstance(decoded, list):
        return [item for item in decoded if isinstance(item, dict)]
    if not isinstance(decoded, dict):
        return []

    candidates = [
        decoded.get("records"),
        decoded.get("orders"),
        decoded.get("items"),
        decoded.get("list"),
    ]
    data = decoded.get("data")
    if isinstance(data, dict):
        candidates.extend([
            data.get("records"),
            data.get("orders"),
            data.get("items"),
            data.get("list"),
        ])

    for candidate in candidates:
        if isinstance(candidate, list):
            return [item for item in candidate if isinstance(item, dict)]
    return []


def _extract_pagination(decoded: Any, request_paging: dict[str, Any]) -> dict[str, Any]:
    response = decoded if isinstance(decoded, dict) else {}
    pagination = response.get("pagination")
    pagination_data = pagination if isinstance(pagination, dict) else {}
    data = response.get("data")
    data_dict = data if isinstance(data, dict) else {}

    next_cursor = _pick_string(
        pagination_data.get("next_cursor"),
        response.get("next_cursor"),
        data_dict.get("next_cursor"),
    )
    next_page = _pick_int(
        pagination_data.get("next_page"),
        response.get("next_page"),
        data_dict.get("next_page"),
    )
    page = _pick_int(
        pagination_data.get("page"),
        response.get("page"),
        data_dict.get("page"),
        request_paging.get("page"),
    )
    page_size = _pick_int(
        pagination_data.get("page_size"),
        response.get("page_size"),
        data_dict.get("page_size"),
        request_paging.get("page_size"),
    )
    has_more = _pick_bool(
        pagination_data.get("has_more"),
        response.get("has_more"),
        data_dict.get("has_more"),
    )
    if has_more is None:
        has_more = bool(next_cursor) or (next_page is not None and page is not None and next_page > page)

    mode = "cursor" if next_cursor else str(request_paging.get("mode") or "page")
    return {
        "mode": mode,
        "page": page,
        "page_size": page_size,
        "next_page": next_page,
        "cursor": request_paging.get("cursor"),
        "next_cursor": next_cursor,
        "has_more": has_more,
    }


def _extract_incremental(decoded: Any, request_incremental: dict[str, Any]) -> dict[str, Any]:
    response = decoded if isinstance(decoded, dict) else {}
    data = response.get("data")
    data_dict = data if isinstance(data, dict) else {}

    next_cursor = _pick_string(
        response.get("next_incremental_cursor"),
        data_dict.get("next_incremental_cursor"),
        response.get("next_cursor"),
        data_dict.get("next_cursor"),
    )
    server_last_pulled_at = _pick_string(
        response.get("server_last_pulled_at"),
        data_dict.get("server_last_pulled_at"),
        response.get("last_pulled_at"),
        data_dict.get("last_pulled_at"),
    )
    return {
        "since": request_incremental.get("since"),
        "request_last_pulled_at": request_incremental.get("last_pulled_at"),
        "request_incremental_cursor": request_incremental.get("incremental_cursor"),
        "next_incremental_cursor": next_cursor,
        "server_last_pulled_at": server_last_pulled_at,
    }


def _pick_string(*values: Any) -> str | None:
    for value in values:
        if value is None:
            continue
        text = str(value).strip()
        if text != "":
            return text
    return None


def _pick_int(*values: Any) -> int | None:
    for value in values:
        if value is None or value == "":
            continue
        try:
            return int(value)
        except (TypeError, ValueError):
            continue
    return None


def _pick_bool(*values: Any) -> bool | None:
    for value in values:
        if isinstance(value, bool):
            return value
        if isinstance(value, str):
            lowered = value.strip().lower()
            if lowered in {"true", "1", "yes", "y"}:
                return True
            if lowered in {"false", "0", "no", "n"}:
                return False
        if isinstance(value, int):
            if value == 1:
                return True
            if value == 0:
                return False
    return None


def _apply_rate_limit(provider: str, rate_limit_per_second: float) -> None:
    if rate_limit_per_second <= 0:
        return

    min_interval = 1.0 / rate_limit_per_second
    now = time.monotonic()
    last = _LAST_REQUEST_TS.get(provider)
    if last is not None:
        wait = min_interval - (now - last)
        if wait > 0:
            time.sleep(wait)
    _LAST_REQUEST_TS[provider] = time.monotonic()


def _resolve_retry_sleep(
    *,
    retry_after_seconds: float | None,
    base_backoff_seconds: float,
    attempt_index: int,
) -> float:
    if retry_after_seconds is not None and retry_after_seconds > 0:
        return retry_after_seconds
    if base_backoff_seconds <= 0:
        return 0.0
    return min(10.0, base_backoff_seconds * (2 ** attempt_index))


def _parse_retry_after(headers: Any) -> float | None:
    if headers is None:
        return None

    retry_after_value = None
    if isinstance(headers, dict):
        retry_after_value = headers.get("Retry-After") or headers.get("retry-after")
    else:
        getter = getattr(headers, "get", None)
        if callable(getter):
            retry_after_value = getter("Retry-After")

    if retry_after_value is None:
        return None

    text = str(retry_after_value).strip()
    if text == "":
        return None

    try:
        seconds = float(text)
        if seconds >= 0:
            return seconds
    except ValueError:
        pass

    try:
        retry_at = parsedate_to_datetime(text)
        now = datetime.now(timezone.utc)
        if retry_at.tzinfo is None:
            retry_at = retry_at.replace(tzinfo=timezone.utc)
        return max(0.0, (retry_at - now).total_seconds())
    except (TypeError, ValueError):
        return None
