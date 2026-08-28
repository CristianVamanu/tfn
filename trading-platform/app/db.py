import datetime
import os

from sqlalchemy import Boolean, DateTime, Float, ForeignKey, Integer, String, Text, create_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, Session, mapped_column, sessionmaker

from app.config import config


class Base(DeclarativeBase):
    pass


class Balance(Base):
    __tablename__ = "balances"

    asset: Mapped[str] = mapped_column(String(20), primary_key=True)
    amount: Mapped[float] = mapped_column(Float, default=0.0)


class Position(Base):
    """Cost-basis tracking, keyed by the underlying ASSET (e.g. "BTC"), not by
    trading pair - so it stays correct regardless of which pair acquired it.
    Only maintained for USDT-quoted trades (see PaperBroker) since that's the
    only quote currency we can express a clean USDT cost basis in."""

    __tablename__ = "positions"

    asset: Mapped[str] = mapped_column(String(20), primary_key=True)
    amount: Mapped[float] = mapped_column(Float, default=0.0)
    avg_entry_price: Mapped[float] = mapped_column(Float, default=0.0)


class Trade(Base):
    __tablename__ = "trades"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    timestamp: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)
    strategy: Mapped[str] = mapped_column(String(40))
    symbol: Mapped[str] = mapped_column(String(20))
    side: Mapped[str] = mapped_column(String(4))
    price: Mapped[float] = mapped_column(Float)
    amount: Mapped[float] = mapped_column(Float)
    quote_amount: Mapped[float] = mapped_column(Float)
    fee: Mapped[float] = mapped_column(Float, default=0.0)
    realized_pnl: Mapped[float] = mapped_column(Float, default=0.0)
    signal_id: Mapped[int | None] = mapped_column(ForeignKey("signal_logs.id"), nullable=True)


class SignalLog(Base):
    __tablename__ = "signal_logs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    timestamp: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)
    strategy: Mapped[str] = mapped_column(String(40))
    symbol: Mapped[str] = mapped_column(String(20))
    side: Mapped[str] = mapped_column(String(4))
    details: Mapped[str] = mapped_column(Text, default="")
    ai_approved: Mapped[bool | None] = mapped_column(Boolean, nullable=True)
    ai_reasoning: Mapped[str] = mapped_column(Text, default="")
    executed: Mapped[bool] = mapped_column(Boolean, default=False)


os.makedirs("data", exist_ok=True)
engine = create_engine(config.database_url, connect_args={"check_same_thread": False})
SessionLocal = sessionmaker(bind=engine)


def init_db() -> None:
    Base.metadata.create_all(engine)
    with SessionLocal() as session:
        if session.get(Balance, "USDT") is None:
            session.add(Balance(asset="USDT", amount=config.paper_starting_balance_usdt))
            session.commit()


def get_session() -> Session:
    return SessionLocal()
