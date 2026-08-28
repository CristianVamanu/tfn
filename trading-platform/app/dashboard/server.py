from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy import select

from app.db import Balance, Position, SignalLog, Trade, get_session, init_db
from app.exchange.market_data import MarketData

app = FastAPI(title="AI Trading Platform")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))
market_data = MarketData()

init_db()


def _build_state() -> dict:
    with get_session() as session:
        balances = session.scalars(select(Balance)).all()
        positions = session.scalars(select(Position).where(Position.amount > 0)).all()
        trades = session.scalars(select(Trade).order_by(Trade.id.desc()).limit(30)).all()
        signals = session.scalars(select(SignalLog).order_by(SignalLog.id.desc()).limit(30)).all()

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

        realized_pnl_total = sum(t.realized_pnl for t in trades)

        return {
            "cash_usdt": cash_usdt,
            "portfolio_value": cash_usdt + positions_value,
            "balances": [{"asset": b.asset, "amount": b.amount} for b in balances],
            "positions": position_rows,
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
                    "details": s.details,
                    "ai_approved": s.ai_approved,
                    "ai_reasoning": s.ai_reasoning,
                    "executed": s.executed,
                }
                for s in signals
            ],
            "realized_pnl_recent": realized_pnl_total,
        }


@app.get("/", response_class=HTMLResponse)
def dashboard(request: Request):
    return templates.TemplateResponse("index.html", {"request": request, "state": _build_state()})


@app.get("/api/state", response_class=JSONResponse)
def api_state():
    return _build_state()
