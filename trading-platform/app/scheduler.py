import datetime
import logging
import os
import time

from sqlalchemy import func, select

from app.ai.reviewer import review_signal
from app.config import config
from app.db import Position, SignalLog, Trade, get_session, init_db, is_trading_halted
from app.exchange.market_data import MarketData
from app.exchange.paper_broker import InsufficientBalance, PaperBroker
from app.strategies import ArbitrageSignal, Signal
from app.strategies.support_resistance import find_signals as find_sr_signals
from app.strategies.triangular_arbitrage import find_arbitrage

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("scheduler")

MIN_TRADE_USDT = 5.0  # skip signals too small to bother executing
HEARTBEAT_PATH = "data/heartbeat.txt"


def _write_heartbeat() -> None:
    os.makedirs(os.path.dirname(HEARTBEAT_PATH), exist_ok=True)
    with open(HEARTBEAT_PATH, "w") as f:
        f.write(datetime.datetime.utcnow().isoformat())


def _log_signal(strategy: str, symbol: str, side: str, details: str, signal: Signal | None = None) -> int:
    with get_session() as session:
        row = SignalLog(strategy=strategy, symbol=symbol, side=side, details=details)
        if signal is not None:
            row.price = signal.price
            row.stop_price = signal.stop_price
            row.target_price = signal.target_price
            row.risk_reward = signal.risk_reward
        session.add(row)
        session.commit()
        session.refresh(row)
        return row.id


def _finalize_signal(
    signal_id: int, ai_approved: bool | None, ai_reasoning: str, executed: bool, risk_rejected_reason: str | None = None
) -> None:
    with get_session() as session:
        row = session.get(SignalLog, signal_id)
        row.ai_approved = ai_approved
        row.ai_reasoning = ai_reasoning
        row.executed = executed
        row.risk_rejected_reason = risk_rejected_reason
        session.merge(row)
        session.commit()


def _check_risk_for_entry() -> tuple[bool, str]:
    """Deterministic risk engine. The AI review gate approving a signal is not
    enough to execute it - these checks are enforced unconditionally and
    cannot be overridden by the AI."""
    if is_trading_halted():
        return False, "Trading halted (kill switch engaged)"

    with get_session() as session:
        today_start = datetime.datetime.utcnow().replace(hour=0, minute=0, second=0, microsecond=0)
        daily_pnl = session.scalar(
            select(func.coalesce(func.sum(Trade.realized_pnl), 0.0)).where(Trade.timestamp >= today_start)
        )
        if daily_pnl <= -config.max_daily_loss_usdt:
            return False, f"Daily loss limit reached ({daily_pnl:.2f} USDT, limit {-config.max_daily_loss_usdt:.2f})"

        open_positions = session.scalar(select(func.count()).select_from(Position).where(Position.amount > 1e-9))
        if open_positions >= config.max_open_positions:
            return False, f"Max open positions reached ({open_positions}/{config.max_open_positions})"

    return True, ""


def _handle_sr_signal(broker: PaperBroker, market_data: MarketData, signal: Signal) -> None:
    signal_id = _log_signal(signal.strategy, signal.symbol, signal.side, signal.reason, signal)
    recent_prices = market_data.get_ohlcv(signal.symbol, limit=10)["close"].round(6).tolist()

    approved, ai_confidence, reasoning = review_signal(
        signal.strategy, signal.symbol, signal.side, signal.price, signal.reason, recent_prices
    )
    log.info("SR signal %s %s (%s) -> AI approved=%s: %s", signal.symbol, signal.side, signal.reason, approved, reasoning)

    if not approved:
        _finalize_signal(signal_id, approved, reasoning, executed=False)
        return

    base_asset, quote_asset = signal.symbol.split("/")

    if signal.side == "buy":
        if signal.risk_reward is not None and signal.risk_reward < config.min_risk_reward:
            reason = f"Risk/reward {signal.risk_reward:.2f} below minimum {config.min_risk_reward}"
            log.info("Risk engine rejected %s: %s", signal.symbol, reason)
            _finalize_signal(signal_id, approved, reasoning, executed=False, risk_rejected_reason=reason)
            return

        risk_ok, risk_reason = _check_risk_for_entry()
        if not risk_ok:
            log.info("Risk engine rejected %s: %s", signal.symbol, risk_reason)
            _finalize_signal(signal_id, approved, reasoning, executed=False, risk_rejected_reason=risk_reason)
            return

    try:
        if signal.side == "buy":
            stake = broker.get_balance(quote_asset) * (config.position_size_pct / 100)
            if stake < MIN_TRADE_USDT:
                log.info("Skipping buy, stake %.2f %s below minimum", stake, quote_asset)
                _finalize_signal(signal_id, approved, reasoning + " (skipped: insufficient stake)", executed=False)
                return
            broker.buy(signal.symbol, stake, signal.price, signal.strategy, signal_id)
        else:
            if is_trading_halted():
                _finalize_signal(signal_id, approved, reasoning, executed=False, risk_rejected_reason="Trading halted (kill switch engaged)")
                return
            held = broker.get_balance(base_asset)
            if held <= 0:
                log.info("Skipping sell, no %s held", base_asset)
                _finalize_signal(signal_id, approved, reasoning + " (skipped: no position held)", executed=False)
                return
            broker.sell(signal.symbol, held, signal.price, signal.strategy, signal_id)
        _finalize_signal(signal_id, approved, reasoning, executed=True)
    except InsufficientBalance as exc:
        log.warning("Execution failed: %s", exc)
        _finalize_signal(signal_id, approved, reasoning + f" (execution failed: {exc})", executed=False)


def _handle_arbitrage_signal(broker: PaperBroker, signal: ArbitrageSignal) -> None:
    signal_id = _log_signal(signal.strategy, signal.symbol, signal.side, signal.reason)
    recent_prices = [round(leg.price, 8) for leg in signal.legs]

    approved, ai_confidence, reasoning = review_signal(
        signal.strategy, signal.symbol, signal.side, signal.price, signal.reason, recent_prices
    )
    log.info("Arb signal %s -> AI approved=%s: %s", signal.symbol, approved, reasoning)

    if not approved:
        _finalize_signal(signal_id, approved, reasoning, executed=False)
        return

    risk_ok, risk_reason = _check_risk_for_entry()
    if not risk_ok:
        log.info("Risk engine rejected arbitrage loop: %s", risk_reason)
        _finalize_signal(signal_id, approved, reasoning, executed=False, risk_rejected_reason=risk_reason)
        return

    stake = broker.get_balance("USDT") * (config.position_size_pct / 100)
    if stake < MIN_TRADE_USDT:
        log.info("Skipping arbitrage, stake %.2f USDT below minimum", stake)
        _finalize_signal(signal_id, approved, reasoning + " (skipped: insufficient stake)", executed=False)
        return

    # Legs are filled sequentially at their scan-time snapshot prices - no slippage
    # modeling, since a real triangular loop executes within milliseconds anyway.
    current_amount = stake
    try:
        for leg in signal.legs:
            if leg.side == "buy":
                trade = broker.buy(leg.symbol, current_amount, leg.price, signal.strategy, signal_id)
                current_amount = trade.amount
            else:
                trade = broker.sell(leg.symbol, current_amount, leg.price, signal.strategy, signal_id)
                current_amount = trade.quote_amount - trade.fee
        loop_pnl = current_amount - stake
        log.info("Arbitrage loop complete: staked %.2f USDT, returned %.2f USDT, pnl %.2f", stake, current_amount, loop_pnl)
        _finalize_signal(signal_id, approved, reasoning + f" | realized loop P&L: {loop_pnl:+.2f} USDT", executed=True)
    except InsufficientBalance as exc:
        log.warning("Arbitrage execution failed mid-loop: %s", exc)
        _finalize_signal(signal_id, approved, reasoning + f" (execution failed mid-loop: {exc})", executed=False)


def run_forever() -> None:
    init_db()
    broker = PaperBroker()
    market_data = MarketData()
    log.info(
        "Scheduler started on %s. Watching %s, arbitrage triangle %s",
        market_data.exchange_id, config.symbols, config.arbitrage_triangle,
    )

    while True:
        try:
            if is_trading_halted():
                log.info("Trading halted (kill switch engaged) - skipping scan cycle")
            else:
                for symbol in config.symbols:
                    for signal in find_sr_signals(market_data, symbol):
                        _handle_sr_signal(broker, market_data, signal)

                arb_signal = find_arbitrage(market_data)
                if arb_signal:
                    _handle_arbitrage_signal(broker, arb_signal)
        except Exception:
            log.exception("Error during scan cycle, continuing")

        _write_heartbeat()
        time.sleep(config.scan_interval_seconds)


if __name__ == "__main__":
    run_forever()
