from dataclasses import dataclass, field


@dataclass
class Signal:
    strategy: str
    symbol: str
    side: str  # "buy" or "sell"
    price: float
    reason: str
    confidence: float  # 0-1, heuristic strength of the signal
    stop_price: float | None = None
    target_price: float | None = None
    risk_reward: float | None = None


@dataclass
class ArbitrageLeg:
    symbol: str
    side: str  # "buy" or "sell"
    price: float


@dataclass
class ArbitrageSignal:
    strategy: str
    symbol: str  # human-readable path label, e.g. "BTC/USDT>ETH/BTC>ETH/USDT"
    side: str  # always "buy" - arbitrage is directional in itself, kept for a uniform Signal-like shape
    price: float  # expected net multiplier on starting capital
    reason: str
    confidence: float
    legs: list[ArbitrageLeg] = field(default_factory=list)
