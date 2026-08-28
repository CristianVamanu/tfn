import logging

import ccxt
import pandas as pd

from app.config import config

log = logging.getLogger("market_data")

# Exchanges vary in which regions/datacenter IP ranges they block on public
# endpoints (e.g. Binance returns HTTP 451 for many hosting-provider IPs).
# Falls back through this list so a VPS being geo-blocked by one exchange
# doesn't take the whole platform down.
FALLBACK_EXCHANGES = ["kraken", "okx", "bybit", "kucoin", "coinbase"]


class MarketData:
    """Read-only wrapper around a ccxt exchange. No API key is ever used here -
    only public endpoints (tickers, order books, OHLCV) so this can never place
    real orders, regardless of what the strategy/AI layers decide."""

    def __init__(self, exchange_id: str = config.exchange_id):
        candidates = [exchange_id] + [e for e in FALLBACK_EXCHANGES if e != exchange_id]
        last_error: Exception | None = None
        for candidate in candidates:
            try:
                exchange_class = getattr(ccxt, candidate)
                exchange = exchange_class({"enableRateLimit": True})
                exchange.load_markets()
                self.exchange = exchange
                self.exchange_id = candidate
                if candidate != exchange_id:
                    log.warning("Exchange %s unavailable, falling back to %s", exchange_id, candidate)
                return
            except Exception as exc:  # noqa: BLE001 - deliberately broad, we're probing connectivity
                last_error = exc
                log.warning("Exchange %s unavailable (%s), trying next fallback", candidate, exc)
        raise RuntimeError(f"No usable exchange found among {candidates}") from last_error

    def get_ticker(self, symbol: str) -> dict:
        return self.exchange.fetch_ticker(symbol)

    def get_last_price(self, symbol: str) -> float:
        return float(self.get_ticker(symbol)["last"])

    def get_ohlcv(self, symbol: str, timeframe: str = "15m", limit: int = 100) -> pd.DataFrame:
        raw = self.exchange.fetch_ohlcv(symbol, timeframe=timeframe, limit=limit)
        df = pd.DataFrame(raw, columns=["timestamp", "open", "high", "low", "close", "volume"])
        df["timestamp"] = pd.to_datetime(df["timestamp"], unit="ms")
        return df
