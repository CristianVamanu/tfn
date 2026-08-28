from app.config import config
from app.db import Balance, Position, Trade, get_session


class InsufficientBalance(Exception):
    pass


class PaperBroker:
    """Simulated order execution against a per-ASSET virtual balance ledger
    (not per trading-pair) - so BTC acquired via BTC/USDT can be spent as the
    quote currency of ETH/BTC, which triangular arbitrage depends on.

    Cost-basis (Position) is only tracked for USDT-quoted trades, since that's
    the only quote currency with a clean USDT-denominated cost basis. Non-USDT
    legs (e.g. ETH/BTC) still move real balances correctly, just without
    updating a misleading P&L number for them."""

    def _split(self, symbol: str) -> tuple[str, str]:
        base, quote = symbol.split("/")
        return base, quote

    def get_balance(self, asset: str) -> float:
        with get_session() as session:
            row = session.get(Balance, asset)
            return row.amount if row else 0.0

    def buy(self, symbol: str, quote_amount: float, price: float, strategy: str, signal_id: int | None) -> Trade:
        base, quote = self._split(symbol)
        fee = quote_amount * (config.trading_fee_pct / 100)
        base_amount = (quote_amount - fee) / price

        with get_session() as session:
            cash = session.get(Balance, quote) or Balance(asset=quote, amount=0.0)
            if cash.amount < quote_amount:
                raise InsufficientBalance(f"Need {quote_amount} {quote}, have {cash.amount}")
            cash.amount -= quote_amount
            session.merge(cash)

            base_bal = session.get(Balance, base) or Balance(asset=base, amount=0.0)
            base_bal.amount += base_amount
            session.merge(base_bal)

            if quote == "USDT":
                pos = session.get(Position, base) or Position(asset=base, amount=0.0, avg_entry_price=0.0)
                new_total = pos.amount + base_amount
                if new_total > 0:
                    pos.avg_entry_price = ((pos.amount * pos.avg_entry_price) + (base_amount * price)) / new_total
                pos.amount = new_total
                session.merge(pos)

            trade = Trade(
                strategy=strategy,
                symbol=symbol,
                side="buy",
                price=price,
                amount=base_amount,
                quote_amount=quote_amount,
                fee=fee,
                signal_id=signal_id,
            )
            session.add(trade)
            session.commit()
            session.refresh(trade)
            return trade

    def sell(self, symbol: str, base_amount: float, price: float, strategy: str, signal_id: int | None) -> Trade:
        base, quote = self._split(symbol)
        gross = base_amount * price
        fee = gross * (config.trading_fee_pct / 100)
        proceeds = gross - fee

        with get_session() as session:
            base_bal = session.get(Balance, base)
            if not base_bal or base_bal.amount < base_amount:
                raise InsufficientBalance(f"Need {base_amount} {base}, have {base_bal.amount if base_bal else 0}")
            base_bal.amount -= base_amount
            session.merge(base_bal)

            quote_bal = session.get(Balance, quote) or Balance(asset=quote, amount=0.0)
            quote_bal.amount += proceeds
            session.merge(quote_bal)

            realized_pnl = 0.0
            if quote == "USDT":
                pos = session.get(Position, base)
                if pos:
                    realized_pnl = (price - pos.avg_entry_price) * base_amount - fee
                    pos.amount = max(0.0, pos.amount - base_amount)
                    if pos.amount <= 1e-12:
                        pos.amount = 0.0
                        pos.avg_entry_price = 0.0
                    session.merge(pos)

            trade = Trade(
                strategy=strategy,
                symbol=symbol,
                side="sell",
                price=price,
                amount=base_amount,
                quote_amount=gross,
                fee=fee,
                realized_pnl=realized_pnl,
                signal_id=signal_id,
            )
            session.add(trade)
            session.commit()
            session.refresh(trade)
            return trade
