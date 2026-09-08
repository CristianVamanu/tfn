import datetime
import hashlib
import os
import secrets

from sqlalchemy import ForeignKey, Integer, String, Text, DateTime, create_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, sessionmaker

from app.config import config


class Base(DeclarativeBase):
    pass


def hash_key(plain: str) -> str:
    return hashlib.sha256(plain.encode()).hexdigest()


def generate_api_key() -> str:
    return "feed_" + secrets.token_urlsafe(32)


PALETTE = ["#7c5cff", "#c9a15a", "#ff9d4d", "#6fd3c7", "#ff5c7a", "#ffe14d", "#5cd6ff", "#8fd15a", "#e07cff"]


class Bot(Base):
    __tablename__ = "bots"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(40), unique=True)
    description: Mapped[str] = mapped_column(Text, default="")
    color: Mapped[str] = mapped_column(String(10))
    api_key_hash: Mapped[str] = mapped_column(String(64), unique=True)
    created_at: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)
    last_post_at: Mapped[datetime.datetime | None] = mapped_column(DateTime, nullable=True)


class Room(Base):
    __tablename__ = "rooms"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    slug: Mapped[str] = mapped_column(String(40), unique=True)
    name: Mapped[str] = mapped_column(String(60))
    description: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)


class Post(Base):
    __tablename__ = "posts"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    room_id: Mapped[int] = mapped_column(ForeignKey("rooms.id"))
    created_at: Mapped[datetime.datetime] = mapped_column(DateTime, default=datetime.datetime.utcnow)
    author_type: Mapped[str] = mapped_column(String(5))  # "bot" or "human"
    bot_id: Mapped[int | None] = mapped_column(ForeignKey("bots.id"), nullable=True)
    author_name: Mapped[str] = mapped_column(String(60), default="")
    content: Mapped[str] = mapped_column(Text)
    parent_id: Mapped[int | None] = mapped_column(ForeignKey("posts.id"), nullable=True)
    likes: Mapped[int] = mapped_column(Integer, default=0)


os.makedirs("data", exist_ok=True)
engine = create_engine(config.database_url, connect_args={"check_same_thread": False})
SessionLocal = sessionmaker(bind=engine)


def init_db() -> None:
    Base.metadata.create_all(engine)
    with SessionLocal() as session:
        if not session.query(Room).filter_by(slug="general").first():
            session.add(Room(slug="general", name="general", description="anything goes"))
            session.commit()


def get_session():
    return SessionLocal()
