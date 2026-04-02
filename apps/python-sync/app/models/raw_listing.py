from datetime import datetime

from sqlalchemy import DateTime, Integer, String
from sqlalchemy.dialects.mysql import JSON
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class RawListing(Base):
    __tablename__ = "raw_listings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    sync_task_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    platform_code: Mapped[str] = mapped_column(String(64), nullable=False)
    shop_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    site_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    event_key: Mapped[str | None] = mapped_column(String(128), nullable=True)
    external_listing_id: Mapped[str | None] = mapped_column(String(128), nullable=True)
    payload_json: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    mapped_status: Mapped[str] = mapped_column(String(32), nullable=False, default="PENDING")
    received_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    processed_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
