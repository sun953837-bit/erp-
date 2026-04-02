from app.adapters.amazon_mock import AmazonMockAdapter
from app.adapters.japan_mock import JapanMockAdapter
from app.adapters.korea_mock import KoreaMockAdapter
from app.adapters.tiktok_mock import TiktokMockAdapter
from app.adapters.xianyu_adapter import XianyuAdapter
from app.adapters.xianyu_mock import XianyuMockAdapter
from app.adapters.zbj_adapter import ZbjAdapter
from app.adapters.base import BasePlatformAdapter


class AdapterFactory:
    def __init__(self) -> None:
        self._providers = {
            "amazon": AmazonMockAdapter(),
            "tiktok": TiktokMockAdapter(),
            "japan": JapanMockAdapter(),
            "korea": KoreaMockAdapter(),
            "xianyu": XianyuAdapter(),
            "zbj": ZbjAdapter(),
        }

    def get(self, platform_code: str) -> BasePlatformAdapter:
        code = (platform_code or "").lower()
        return self._providers.get(code, XianyuMockAdapter())


adapter_factory = AdapterFactory()
