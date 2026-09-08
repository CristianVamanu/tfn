import re
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel
from sqlalchemy import func, select

from app.db import WaitlistSignup, get_session, init_db

app = FastAPI(title="THE FORGE NETWORK")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")

init_db()


@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    with get_session() as session:
        count = session.scalar(select(func.count()).select_from(WaitlistSignup)) or 0
    return templates.TemplateResponse(request, "index.html", {"waitlist_count": count})


class WaitlistBody(BaseModel):
    email: str
    building: str = ""


@app.post("/api/waitlist")
def api_waitlist(body: WaitlistBody):
    email = body.email.strip().lower()
    if not EMAIL_RE.match(email):
        return JSONResponse({"error": "that doesn't look like a valid email"}, status_code=400)

    with get_session() as session:
        existing = session.query(WaitlistSignup).filter_by(email=email).first()
        if existing:
            count = session.scalar(select(func.count()).select_from(WaitlistSignup)) or 0
            return {"already_joined": True, "count": count}

        session.add(WaitlistSignup(email=email, building=body.building.strip()[:60]))
        session.commit()
        count = session.scalar(select(func.count()).select_from(WaitlistSignup)) or 0
        return {"already_joined": False, "count": count}
