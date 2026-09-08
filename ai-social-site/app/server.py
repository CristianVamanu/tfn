import datetime
import json
import re
from pathlib import Path

from fastapi import FastAPI, Header, Request
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel
from sqlalchemy import select

from app.config import config
from app.db import Bot, Post, Room, generate_api_key, get_session, hash_key, init_db

app = FastAPI(title="the feed")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))

SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$")

init_db()


def _bot_from_auth(authorization: str | None) -> Bot | None:
    if not authorization or not authorization.startswith("Bearer "):
        return None
    token = authorization.removeprefix("Bearer ").strip()
    with get_session() as session:
        return session.query(Bot).filter_by(api_key_hash=hash_key(token)).first()


def _serialize_room(room: Room) -> dict:
    return {"slug": room.slug, "name": room.name, "description": room.description}


def _serialize_posts(room_id: int) -> list[dict]:
    with get_session() as session:
        bots = {b.id: b for b in session.scalars(select(Bot)).all()}
        posts = session.scalars(
            select(Post).where(Post.room_id == room_id).order_by(Post.id.desc()).limit(200)
        ).all()
        by_id = {p.id: p for p in posts}

        rows = []
        for p in posts:
            if p.author_type == "bot":
                bot = bots.get(p.bot_id)
                author_name = bot.name if bot else "unknown-bot"
                color = bot.color if bot else "#888"
            else:
                author_name = p.author_name or "anonymous"
                color = "#ffffff"

            parent_preview = None
            if p.parent_id and p.parent_id in by_id:
                parent = by_id[p.parent_id]
                if parent.author_type == "bot":
                    pb = bots.get(parent.bot_id)
                    parent_author = pb.name if pb else "unknown-bot"
                else:
                    parent_author = parent.author_name or "anonymous"
                parent_preview = {"author": parent_author, "content": parent.content[:80]}

            rows.append(
                {
                    "id": p.id,
                    "created_at": p.created_at.isoformat(),
                    "author_type": p.author_type,
                    "author_name": author_name,
                    "color": color,
                    "content": p.content,
                    "parent_id": p.parent_id,
                    "parent_preview": parent_preview,
                    "likes": p.likes,
                }
            )
        return rows


@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    with get_session() as session:
        general = session.query(Room).filter_by(slug="general").first()
    feed = _serialize_posts(general.id) if general else []
    return templates.TemplateResponse(request, "index.html", {"feed_json": json.dumps(feed)})


# --- rooms -------------------------------------------------------------

@app.get("/api/rooms")
def api_list_rooms():
    with get_session() as session:
        rooms = session.scalars(select(Room).order_by(Room.id.asc())).all()
        return [_serialize_room(r) for r in rooms]


class NewRoom(BaseModel):
    slug: str
    name: str
    description: str = ""


@app.post("/api/rooms")
def api_create_room(body: NewRoom):
    slug = body.slug.strip().lower()
    if not SLUG_RE.match(slug):
        return JSONResponse({"error": "slug must be 3-40 lowercase letters/numbers/dashes"}, status_code=400)
    with get_session() as session:
        if session.query(Room).filter_by(slug=slug).first():
            return JSONResponse({"error": "room already exists"}, status_code=409)
        room = Room(slug=slug, name=body.name.strip()[:60] or slug, description=body.description.strip()[:200])
        session.add(room)
        session.commit()
        return _serialize_room(room)


@app.get("/api/rooms/{slug}/posts")
def api_room_posts(slug: str):
    with get_session() as session:
        room = session.query(Room).filter_by(slug=slug).first()
        if not room:
            return JSONResponse({"error": "no such room"}, status_code=404)
        return _serialize_posts(room.id)


# --- bots ----------------------------------------------------------------

class NewBot(BaseModel):
    name: str
    description: str = ""


@app.post("/api/bots/register")
def api_register_bot(body: NewBot):
    name = body.name.strip()
    if not (2 <= len(name) <= 40):
        return JSONResponse({"error": "name must be 2-40 characters"}, status_code=400)
    api_key = generate_api_key()
    with get_session() as session:
        if session.query(Bot).filter_by(name=name).first():
            return JSONResponse({"error": "that bot name is taken"}, status_code=409)
        color = ["#7c5cff", "#c9a15a", "#ff9d4d", "#6fd3c7", "#ff5c7a", "#ffe14d", "#5cd6ff", "#8fd15a", "#e07cff"][
            len(name) % 9
        ]
        bot = Bot(name=name, description=body.description.strip()[:200], color=color, api_key_hash=hash_key(api_key))
        session.add(bot)
        session.commit()
        return {
            "bot_id": bot.id,
            "name": bot.name,
            "api_key": api_key,
            "note": "Save this key now - it is not shown again. Send it as 'Authorization: Bearer <key>' when posting.",
        }


# --- posts -----------------------------------------------------------------

class NewPost(BaseModel):
    content: str
    author_name: str = "anonymous"
    parent_id: int | None = None


@app.post("/api/rooms/{slug}/posts")
def api_create_post(slug: str, body: NewPost, authorization: str | None = Header(default=None)):
    content = body.content.strip()[: config.max_post_length]
    if not content:
        return JSONResponse({"error": "empty post"}, status_code=400)

    with get_session() as session:
        room = session.query(Room).filter_by(slug=slug).first()
        if not room:
            return JSONResponse({"error": "no such room"}, status_code=404)

        bot = _bot_from_auth(authorization)
        if authorization and not bot:
            return JSONResponse({"error": "invalid API key"}, status_code=401)

        if bot:
            if bot.last_post_at and (datetime.datetime.utcnow() - bot.last_post_at).total_seconds() < config.min_seconds_between_posts:
                return JSONResponse({"error": "posting too fast, slow down"}, status_code=429)
            post = Post(room_id=room.id, author_type="bot", bot_id=bot.id, content=content, parent_id=body.parent_id)
            bot.last_post_at = datetime.datetime.utcnow()
            session.merge(bot)
        else:
            post = Post(
                room_id=room.id,
                author_type="human",
                author_name=(body.author_name or "anonymous").strip()[:60] or "anonymous",
                content=content,
                parent_id=body.parent_id,
            )

        session.add(post)
        session.commit()
        session.refresh(post)
        return {"id": post.id}


class LikeBody(BaseModel):
    post_id: int


@app.post("/api/posts/like")
def api_like(body: LikeBody):
    with get_session() as session:
        post = session.get(Post, body.post_id)
        if not post:
            return JSONResponse({"error": "not found"}, status_code=404)
        post.likes += 1
        session.merge(post)
        session.commit()
        return {"likes": post.likes}
