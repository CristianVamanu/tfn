import os
from dataclasses import dataclass, field

from dotenv import load_dotenv

load_dotenv()


def _list_env(name: str, default: str) -> list[str]:
    return [s.strip() for s in os.getenv(name, default).split(",") if s.strip()]


@dataclass(frozen=True)
class Config:
    exchange_id: str = os.getenv("EXCHANGE_ID", "binance")
    symbols: list[str] = field(default_factory=lambda: _list_env("SYMBOLS", "BTC/USDT,ETH/USDT"))
    arbitrage_triangle: list[str] = field(
        default_factory=lambda: _list_env("ARBITRAGE_TRIANGLE", "BTC/USDT,ETH/BTC,ETH/USDT")
    )
    paper_starting_balance_usdt: float = float(os.getenv("PAPER_STARTING_BALANCE_USDT", "10000"))
    trading_fee_pct: float = float(os.getenv("TRADING_FEE_PCT", "0.1"))
    min_arb_profit_pct: float = float(os.getenv("MIN_ARB_PROFIT_PCT", "0.15"))
    position_size_pct: float = float(os.getenv("POSITION_SIZE_PCT", "10"))
    max_daily_loss_usdt: float = float(os.getenv("MAX_DAILY_LOSS_USDT", "200"))
    max_open_positions: int = int(os.getenv("MAX_OPEN_POSITIONS", "3"))
    min_risk_reward: float = float(os.getenv("MIN_RISK_REWARD", "1.5"))
    scan_interval_seconds: int = int(os.getenv("SCAN_INTERVAL_SECONDS", "60"))
    anthropic_api_key: str = os.getenv("ANTHROPIC_API_KEY", "")
    anthropic_workspace_id: str = os.getenv("ANTHROPIC_WORKSPACE_ID", "")
    claude_model: str = os.getenv("CLAUDE_MODEL", "claude-sonnet-5")
    database_url: str = os.getenv("DATABASE_URL", "sqlite:///./data/trading.db")
    dashboard_port: int = int(os.getenv("DASHBOARD_PORT", "8001"))


config = Config()
