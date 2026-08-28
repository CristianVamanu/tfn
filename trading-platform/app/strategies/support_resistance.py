import pandas as pd

from app.exchange.market_data import MarketData
from app.strategies import Signal

PIVOT_WINDOW = 3
LEVEL_CLUSTER_PCT = 0.5  # merge pivots within this % of each other into one level
PROXIMITY_PCT = 0.6  # price must be within this % of a level to trigger a signal
ATR_PERIOD = 14
ATR_STOP_BUFFER = 0.5  # stop placed this many ATRs below the support level itself


def _atr(df: pd.DataFrame, period: int = ATR_PERIOD) -> float:
    high, low, close = df["high"], df["low"], df["close"]
    prev_close = close.shift(1)
    true_range = pd.concat(
        [high - low, (high - prev_close).abs(), (low - prev_close).abs()], axis=1
    ).max(axis=1)
    return float(true_range.rolling(period).mean().iloc[-1])


def _find_pivots(df: pd.DataFrame) -> tuple[list[float], list[float]]:
    highs, lows = [], []
    n = len(df)
    for i in range(PIVOT_WINDOW, n - PIVOT_WINDOW):
        window_high = df["high"].iloc[i - PIVOT_WINDOW : i + PIVOT_WINDOW + 1]
        window_low = df["low"].iloc[i - PIVOT_WINDOW : i + PIVOT_WINDOW + 1]
        if df["high"].iloc[i] == window_high.max():
            highs.append(float(df["high"].iloc[i]))
        if df["low"].iloc[i] == window_low.min():
            lows.append(float(df["low"].iloc[i]))
    return highs, lows


def _cluster_levels(levels: list[float]) -> list[dict]:
    """Merge nearby pivots into zones, each with a price and a touch count
    (more touches = a level the market has repeatedly respected)."""
    clusters: list[dict] = []
    for level in sorted(levels):
        placed = False
        for cluster in clusters:
            if abs(level - cluster["price"]) / cluster["price"] * 100 <= LEVEL_CLUSTER_PCT:
                cluster["price"] = (cluster["price"] * cluster["touches"] + level) / (cluster["touches"] + 1)
                cluster["touches"] += 1
                placed = True
                break
        if not placed:
            clusters.append({"price": level, "touches": 1})
    return clusters


def compute_levels(df: pd.DataFrame) -> dict:
    """Public entry point for anything that just wants the support/resistance
    zones and ATR for a candle set (e.g. the chart-overlay API), without going
    through the full signal-generation logic in find_signals()."""
    pivot_highs, pivot_lows = _find_pivots(df)
    return {
        "resistance": _cluster_levels(pivot_highs),
        "support": _cluster_levels(pivot_lows),
        "atr": _atr(df),
    }


def find_signals(market_data: MarketData, symbol: str) -> list[Signal]:
    df = market_data.get_ohlcv(symbol, timeframe="15m", limit=150)
    if len(df) < PIVOT_WINDOW * 2 + 5:
        return []

    levels = compute_levels(df)
    resistance_zones = levels["resistance"]
    support_zones = levels["support"]

    price = float(df["close"].iloc[-1])
    prev_close = float(df["close"].iloc[-2])
    signals: list[Signal] = []
    atr = levels["atr"]

    nearest_support = min(support_zones, key=lambda z: abs(z["price"] - price), default=None)
    if nearest_support:
        distance_pct = abs(price - nearest_support["price"]) / price * 100
        bouncing = price > prev_close
        if distance_pct <= PROXIMITY_PCT and price >= nearest_support["price"] and bouncing:
            confidence = min(1.0, nearest_support["touches"] / 3) * (1 - distance_pct / PROXIMITY_PCT)

            stop_price = nearest_support["price"] - (atr * ATR_STOP_BUFFER if atr and atr > 0 else 0)
            zones_above = [z for z in resistance_zones if z["price"] > price]
            target_zone = min(zones_above, key=lambda z: z["price"], default=None)
            target_price = target_zone["price"] if target_zone else price + (atr * 3 if atr and atr > 0 else 0)
            risk = price - stop_price
            reward = target_price - price
            risk_reward = round(reward / risk, 2) if risk > 0 else None

            signals.append(
                Signal(
                    strategy="support_resistance",
                    symbol=symbol,
                    side="buy",
                    price=price,
                    reason=(
                        f"Price {price:.4f} bouncing off support at {nearest_support['price']:.4f} "
                        f"({nearest_support['touches']} prior touches, {distance_pct:.2f}% away)"
                    ),
                    confidence=round(confidence, 2),
                    stop_price=round(stop_price, 6) if stop_price else None,
                    target_price=round(target_price, 6) if target_price else None,
                    risk_reward=risk_reward,
                )
            )

    nearest_resistance = min(resistance_zones, key=lambda z: abs(z["price"] - price), default=None)
    if nearest_resistance:
        distance_pct = abs(price - nearest_resistance["price"]) / price * 100
        rejecting = price < prev_close
        if distance_pct <= PROXIMITY_PCT and price <= nearest_resistance["price"] and rejecting:
            confidence = min(1.0, nearest_resistance["touches"] / 3) * (1 - distance_pct / PROXIMITY_PCT)
            signals.append(
                Signal(
                    strategy="support_resistance",
                    symbol=symbol,
                    side="sell",
                    price=price,
                    reason=(
                        f"Price {price:.4f} rejecting resistance at {nearest_resistance['price']:.4f} "
                        f"({nearest_resistance['touches']} prior touches, {distance_pct:.2f}% away)"
                    ),
                    confidence=round(confidence, 2),
                )
            )

    return signals
