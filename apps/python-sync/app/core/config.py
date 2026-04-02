from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_env: str = "dev"
    log_level: str = "INFO"

    mysql_host: str = "mysql"
    mysql_port: int = 3306
    mysql_db: str = "kuajing_v1"
    mysql_user: str = "kuajing"
    mysql_password: str = "kuajing123"

    redis_host: str = "redis"
    redis_port: int = 6379

    worker_batch_size: int = 20
    worker_max_pull_records_per_task: int = 200
    worker_max_payload_bytes: int = 1048576
    worker_max_row_payload_bytes: int = 262144

    xianyu_orders_source_mode: str = "mock"
    xianyu_orders_endpoint: str = ""
    xianyu_access_token: str = ""
    xianyu_app_key: str = ""
    xianyu_http_timeout_seconds: float = 8.0
    xianyu_http_retry_attempts: int = 2
    xianyu_http_retry_backoff_seconds: float = 0.5
    xianyu_http_rate_limit_per_second: float = 5.0
    xianyu_orders_extra_params_json: str = ""
    xianyu_refunds_source_mode: str = "mock"
    xianyu_refunds_endpoint: str = ""
    xianyu_refunds_extra_params_json: str = ""
    xianyu_listings_source_mode: str = "mock"
    xianyu_listings_endpoint: str = ""
    xianyu_listings_extra_params_json: str = ""

    zbj_orders_source_mode: str = "mock"
    zbj_orders_endpoint: str = ""
    zbj_access_token: str = ""
    zbj_app_key: str = ""
    zbj_http_timeout_seconds: float = 8.0
    zbj_http_retry_attempts: int = 2
    zbj_http_retry_backoff_seconds: float = 0.5
    zbj_http_rate_limit_per_second: float = 5.0
    zbj_orders_extra_params_json: str = ""
    zbj_refunds_source_mode: str = "mock"
    zbj_refunds_endpoint: str = ""
    zbj_refunds_extra_params_json: str = ""
    zbj_services_source_mode: str = "mock"
    zbj_services_endpoint: str = ""
    zbj_services_extra_params_json: str = ""

    channel_account_sync_enabled: bool = True
    channel_account_sync_timeout_seconds: float = 2.0
    channel_account_sync_internal_token: str = ""
    php_api_base_url: str = "http://localhost:8000"

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
    )

    @property
    def sqlalchemy_url(self) -> str:
        return (
            f"mysql+pymysql://{self.mysql_user}:{self.mysql_password}"
            f"@{self.mysql_host}:{self.mysql_port}/{self.mysql_db}?charset=utf8mb4"
        )

    @property
    def redis_url(self) -> str:
        return f"redis://{self.redis_host}:{self.redis_port}/0"


settings = Settings()
