import datetime
import os

from sqlalchemy import DateTime, Integer, String, create_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, sessionmaker

DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///./data/tfn.db")


class Base(DeclarativeBase):
    pass


class WaitlistSignup(Base):
    __tablename__ = "waitlist_signups"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    created_at: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)
    email: Mapped[str] = mapped_column(String(255), unique=True)
    building: Mapped[str] = mapped_column(String(60), default="")


os.makedirs("data", exist_ok=True)
engine = create_engine(DATABASE_URL, connect_args={"check_same_thread": False})
SessionLocal = sessionmaker(bind=engine)


def init_db() -> None:
    Base.metadata.create_all(engine)


def get_session():
    return SessionLocal()
