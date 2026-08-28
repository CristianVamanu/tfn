import datetime
import json
import os
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse
from pydantic import BaseModel
from fastapi.templating import Jinja2Templates
from sqlalchemy import func, select

from app.config import config
from app.db import (
    Balance,
    Position,
    SignalLog,
    Trade,
    get_session,
    init_db,
    is_trading_halted,
    set_trading_halted,
)
from app.exchange.market_data import MarketData
from app.strategies.support_resistance import compute_levels

app = FastAPI(title="THE FORGE")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))
market_data = MarketData()

HEARTBEAT_PATH = "data/heartbeat.txt"

init_db()


def _bot_status() -> dict:
    if not os.path.exists(HEARTBEAT_PATH):
        return {"status": "unknown", "seconds_ago": None}
    with open(HEARTBEAT_PATH) as f:
        last = datetime.datetime.fromisoformat(f.read().strip())
    seconds_ago = (datetime.datetime.utcnow() - last).total_seconds()
    stale_after = config.scan_interval_seconds * 3
    return {"status": "stale" if seconds_ago > stale_after else "running", "seconds_ago": int(seconds_ago)}


def _equity_curve(current_portfolio_value: float) -> list[dict]:
    with get_session() as session:
        trades = session.scalars(select(Trade).order_by(Trade.id.asc()).limit(500)).all()

    curve = [{"t": (trades[0].timestamp if trades else datetime.datetime.utcnow()).isoformat(), "v": config.paper_starting_balance_usdt}]
    running = config.paper_starting_balance_usdt
    for t in trades:
        running += t.realized_pnl
        curve.append({"t": t.timestamp.isoformat(), "v": round(running, 2)})
    curve.append({"t": datetime.datetime.utcnow().isoformat(), "v": round(current_portfolio_value, 2)})
    return curve


def _watchlist() -> list[dict]:
    rows = []
    with get_session() as session:
        for symbol in config.symbols:
            latest_signal = session.scalars(
                select(SignalLog).where(SignalLog.symbol == symbol).order_by(SignalLog.id.desc()).limit(1)
            ).first()
            try:
                ticker = market_data.get_ticker(symbol)
                price = ticker.get("last")
                change_pct = ticker.get("percentage")
                volume = ticker.get("quoteVolume")
                data_ok = price is not None
            except Exception:
                price, change_pct, volume, data_ok = None, None, None, False

            signal_label = "—"
            signal_score = None
            if latest_signal and latest_signal.ai_approved and latest_signal.executed:
                signal_label = latest_signal.side.upper()
                signal_score = None
            rows.append(
                {
                    "symbol": symbol,
                    "price": price,
                    "change_pct": change_pct,
                    "volume": volume,
                    "data_ok": data_ok,
                    "signal": signal_label,
                    "signal_score": signal_score,
                }
            )
    return rows


def _risk_panel() -> dict:
    with get_session() as session:
        today_start = datetime.datetime.utcnow().replace(hour=0, minute=0, second=0, microsecond=0)
        daily_pnl = session.scalar(
            select(func.coalesce(func.sum(Trade.realized_pnl), 0.0)).where(Trade.timestamp >= today_start)
        )
        open_positions = session.scalar(select(func.count()).select_from(Position).where(Position.amount > 1e-9))

    return {
        "daily_pnl": daily_pnl,
        "max_daily_loss": config.max_daily_loss_usdt,
        "daily_loss_breached": daily_pnl <= -config.max_daily_loss_usdt,
        "open_positions": open_positions,
        "max_open_positions": config.max_open_positions,
        "position_size_pct": config.position_size_pct,
        "min_risk_reward": config.min_risk_reward,
        "halted": is_trading_halted(),
    }


def _build_state() -> dict:
    with get_session() as session:
        balances = session.scalars(select(Balance)).all()
        positions = session.scalars(select(Position).where(Position.amount > 0)).all()
        trades = session.scalars(select(Trade).order_by(Trade.id.desc()).limit(30)).all()
        signals = session.scalars(select(SignalLog).order_by(SignalLog.id.desc()).limit(40)).all()
        all_time_realized_pnl = session.query(Trade).with_entities(Trade.realized_pnl).all()

        cash_usdt = next((b.amount for b in balances if b.asset == "USDT"), 0.0)

        position_rows = []
        positions_value = 0.0
        for pos in positions:
            live_price = None
            try:
                live_price = market_data.get_last_price(f"{pos.asset}/USDT")
            except Exception:
                pass
            market_value = pos.amount * live_price if live_price else None
            unrealized_pnl = (live_price - pos.avg_entry_price) * pos.amount if live_price else None
            if market_value:
                positions_value += market_value
            position_rows.append(
                {
                    "asset": pos.asset,
                    "amount": pos.amount,
                    "avg_entry_price": pos.avg_entry_price,
                    "live_price": live_price,
                    "market_value": market_value,
                    "unrealized_pnl": unrealized_pnl,
                }
            )

        portfolio_value = cash_usdt + positions_value
        realized_pnl_recent = sum(t.realized_pnl for t in trades)
        realized_pnl_all_time = sum(row[0] for row in all_time_realized_pnl)

        return {
            "mode": "PAPER",
            "exchange": market_data.exchange_id,
            "cash_usdt": cash_usdt,
            "portfolio_value": portfolio_value,
            "starting_balance": config.paper_starting_balance_usdt,
            "total_return_pct": (portfolio_value / config.paper_starting_balance_usdt - 1) * 100,
            "balances": [{"asset": b.asset, "amount": b.amount} for b in balances],
            "positions": position_rows,
            "bot": _bot_status(),
            "risk": _risk_panel(),
            "equity_curve": _equity_curve(portfolio_value),
            "watchlist": _watchlist(),
            "arbitrage_triangle": " > ".join(config.arbitrage_triangle),
            "trades": [
                {
                    "id": t.id,
                    "timestamp": t.timestamp.isoformat(),
                    "strategy": t.strategy,
                    "symbol": t.symbol,
                    "side": t.side,
                    "price": t.price,
                    "amount": t.amount,
                    "quote_amount": t.quote_amount,
                    "fee": t.fee,
                    "realized_pnl": t.realized_pnl,
                }
                for t in trades
            ],
            "signals": [
                {
                    "id": s.id,
                    "timestamp": s.timestamp.isoformat(),
                    "strategy": s.strategy,
                    "symbol": s.symbol,
                    "side": s.side,
                    "price": s.price,
                    "details": s.details,
                    "ai_approved": s.ai_approved,
                    "ai_reasoning": s.ai_reasoning,
                    "executed": s.executed,
                    "stop_price": s.stop_price,
                    "target_price": s.target_price,
                    "risk_reward": s.risk_reward,
                    "risk_rejected_reason": s.risk_rejected_reason,
                }
                for s in signals
            ],
            "realized_pnl_recent": realized_pnl_recent,
            "realized_pnl_all_time": realized_pnl_all_time,
        }


@app.get("/", response_class=HTMLResponse)
def dashboard(request: Request):
    state = _build_state()
    return templates.TemplateResponse(request, "index.html", {"state_json": json.dumps(state), "watchlist": state["watchlist"]})


@app.get("/api/state", response_class=JSONResponse)
def api_state():
    return _build_state()


@app.get("/api/ohlcv")
def api_ohlcv(symbol: str = "BTC/USDT", timeframe: str = "15m", limit: int = 200):
    try:
        df = market_data.get_ohlcv(symbol, timeframe=timeframe, limit=limit)
    except Exception as exc:
        return JSONResponse({"error": str(exc)}, status_code=502)
    return [
        {
            "t": int(row.timestamp.timestamp()),
            "o": row.open,
            "h": row.high,
            "l": row.low,
            "c": row.close,
            "v": row.volume,
        }
        for row in df.itertuples()
    ]


@app.get("/api/levels")
def api_levels(symbol: str = "BTC/USDT"):
    try:
        df = market_data.get_ohlcv(symbol, timeframe="15m", limit=150)
        levels = compute_levels(df)
    except Exception as exc:
        return JSONResponse({"error": str(exc)}, status_code=502)
    return {
        "support": [{"price": z["price"], "touches": z["touches"]} for z in levels["support"]],
        "resistance": [{"price": z["price"], "touches": z["touches"]} for z in levels["resistance"]],
    }


class KillSwitchBody(BaseModel):
    halted: bool


@app.post("/api/kill-switch")
def api_kill_switch(body: KillSwitchBody):
    set_trading_halted(body.halted)
    return {"halted": body.halted}
