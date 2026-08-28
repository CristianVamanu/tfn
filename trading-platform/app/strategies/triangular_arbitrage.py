from app.config import config
from app.exchange.market_data import MarketData
from app.strategies import ArbitrageLeg, ArbitrageSignal


def find_arbitrage(market_data: MarketData, triangle: list[str] = config.arbitrage_triangle) -> ArbitrageSignal | None:
    """Scans a single-exchange triangular loop (e.g. USDT -> BTC -> ETH -> USDT)
    for a pricing inefficiency, in either direction. Fully executable on one
    exchange with no cross-exchange transfers, which is what makes this form
    of arbitrage actually viable for a solo/personal bot."""

    if len(triangle) != 3:
        raise ValueError("triangle must have exactly 3 legs, e.g. ['BTC/USDT', 'ETH/BTC', 'ETH/USDT']")

    leg_a, leg_b, leg_c = triangle  # e.g. BTC/USDT, ETH/BTC, ETH/USDT
    price_a = market_data.get_last_price(leg_a)
    price_b = market_data.get_last_price(leg_b)
    price_c = market_data.get_last_price(leg_c)

    fee_multiplier = (1 - config.trading_fee_pct / 100) ** 3
    label = ">".join(triangle)

    # Forward: quote -> base(a) -> base(b) -> quote, e.g. USDT -> BTC -> ETH -> USDT
    forward_multiplier = (1 / price_a) * (1 / price_b) * price_c * fee_multiplier
    forward_profit_pct = (forward_multiplier - 1) * 100

    # Reverse: quote -> base(c's base) -> ... -> quote, e.g. USDT -> ETH -> BTC -> USDT
    reverse_multiplier = (1 / price_c) * price_b * price_a * fee_multiplier
    reverse_profit_pct = (reverse_multiplier - 1) * 100

    if forward_profit_pct >= config.min_arb_profit_pct and forward_profit_pct >= reverse_profit_pct:
        return ArbitrageSignal(
            strategy="triangular_arbitrage",
            symbol=label,
            side="buy",
            price=forward_multiplier,
            reason=(
                f"Forward loop {label} nets {forward_profit_pct:.3f}% after fees "
                f"({leg_a}={price_a}, {leg_b}={price_b}, {leg_c}={price_c})"
            ),
            confidence=min(1.0, forward_profit_pct / (config.min_arb_profit_pct * 3)),
            legs=[
                ArbitrageLeg(symbol=leg_a, side="buy", price=price_a),
                ArbitrageLeg(symbol=leg_b, side="buy", price=price_b),
                ArbitrageLeg(symbol=leg_c, side="sell", price=price_c),
            ],
        )

    if reverse_profit_pct >= config.min_arb_profit_pct:
        return ArbitrageSignal(
            strategy="triangular_arbitrage",
            symbol=label,
            side="buy",
            price=reverse_multiplier,
            reason=(
                f"Reverse loop {label} nets {reverse_profit_pct:.3f}% after fees "
                f"({leg_a}={price_a}, {leg_b}={price_b}, {leg_c}={price_c})"
            ),
            confidence=min(1.0, reverse_profit_pct / (config.min_arb_profit_pct * 3)),
            legs=[
                ArbitrageLeg(symbol=leg_c, side="buy", price=price_c),
                ArbitrageLeg(symbol=leg_b, side="sell", price=price_b),
                ArbitrageLeg(symbol=leg_a, side="sell", price=price_a),
            ],
        )

    return None
