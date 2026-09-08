import datetime
import os

from sqlalchemy import DateTime, Integer, String, Text, create_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, sessionmaker

from app.config import config


class Base(DeclarativeBase):
    pass


class Analysis(Base):
    __tablename__ = "analyses"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    created_at: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)
    title: Mapped[str] = mapped_column(String(120))
    input_text: Mapped[str] = mapped_column(Text)
    result_json: Mapped[str] = mapped_column(Text)


os.makedirs("data", exist_ok=True)
engine = create_engine(config.database_url, connect_args={"check_same_thread": False})
SessionLocal = sessionmaker(bind=engine)


def init_db() -> None:
    Base.metadata.create_all(engine)


def get_session():
    return SessionLocal()
