"""One-time migration: copy rows from the SQLite DB into MySQL, preserving
ids, timestamps, and skipping rows already present on the MySQL side (safe
to re-run).

Usage:
  SQLITE_URL=sqlite:///./data/tfn.db \
  MYSQL_URL=mysql+pymysql://tfn_app:PASSWORD@127.0.0.1:3306/tfn?charset=utf8mb4 \
  /opt/tfn-landing/venv/bin/python3 scripts/migrate_sqlite_to_mysql.py
"""
import os
import sys

from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from app.db import Base, ForgeProfile, User, WaitlistSignup  # noqa: E402

SQLITE_URL = os.environ["SQLITE_URL"]
MYSQL_URL = os.environ["MYSQL_URL"]

src_engine = create_engine(SQLITE_URL, connect_args={"check_same_thread": False})
dst_engine = create_engine(MYSQL_URL)

Base.metadata.create_all(dst_engine)

SrcSession = sessionmaker(bind=src_engine)
DstSession = sessionmaker(bind=dst_engine)

src = SrcSession()
dst = DstSession()


def copy_table(model, label):
    rows = src.query(model).all()
    copied = 0
    for row in rows:
        if dst.get(model, row.id):
            continue
        data = {c.name: getattr(row, c.name) for c in model.__table__.columns}
        dst.merge(model(**data))
        copied += 1
    dst.commit()
    print(f"{label}: copied {copied} of {len(rows)} rows (rest already present)")


copy_table(WaitlistSignup, "waitlist_signups")
copy_table(User, "users")
copy_table(ForgeProfile, "forge_profiles")

print("Migration complete.")
