import ccxt
import pandas as pd

from app.config import config


class MarketData:
    """Read-only wrapper around a ccxt exchange. No API key is ever used here -
    only public endpoints (tickers, order books, OHLCV) so this can never place
    real orders, regardless of what the strategy/AI layers decide."""

    def __init__(self, exchange_id: str = config.exchange_id):
        exchange_class = getattr(ccxt, exchange_id)
        self.exchange = exchange_class({"enableRateLimit": True})

    def get_ticker(self, symbol: str) -> dict:
        return self.exchange.fetch_ticker(symbol)

    def get_last_price(self, symbol: str) -> float:
        return float(self.get_ticker(symbol)["last"])

    def get_ohlcv(self, symbol: str, timeframe: str = "15m", limit: int = 100) -> pd.DataFrame:
        raw = self.exchange.fetch_ohlcv(symbol, timeframe=timeframe, limit=limit)
        df = pd.DataFrame(raw, columns=["timestamp", "open", "high", "low", "close", "volume"])
        df["timestamp"] = pd.to_datetime(df["timestamp"], unit="ms")
        return df
