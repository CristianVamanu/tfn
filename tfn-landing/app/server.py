import os
import re
import secrets
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel
from sqlalchemy import func, select
from starlette.middleware.sessions import SessionMiddleware

from app.auth import hash_password, verify_password
from app.db import ForgeProfile, ForgeStep, User, WaitlistSignup, get_session, init_db
from app.roadmap_templates import get_template

app = FastAPI(title="THE FORGE NETWORK")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))

app.add_middleware(
    SessionMiddleware,
    secret_key=os.getenv("SECRET_KEY", secrets.token_hex(32)),
    https_only=True,
    same_site="lax",
    max_age=60 * 60 * 24 * 14,
)

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
ADMIN_EMAIL = os.getenv("ADMIN_EMAIL", "").strip().lower()

init_db()


def get_current_user(request: Request):
    user_id = request.session.get("user_id")
    if not user_id:
        return None
    with get_session() as session:
        user = session.get(User, user_id)
        if not user:
            return None
        return {"id": user.id, "email": user.email, "is_admin": user.is_admin, "created_at": user.created_at}


@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    with get_session() as session:
        count = session.scalar(select(func.count()).select_from(WaitlistSignup)) or 0
    return templates.TemplateResponse(request, "index.html", {"waitlist_count": count, "user": get_current_user(request)})


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


# ---------- auth ----------

@app.get("/signup", response_class=HTMLResponse)
def signup_page(request: Request):
    if get_current_user(request):
        return RedirectResponse("/dashboard", status_code=303)
    return templates.TemplateResponse(request, "signup.html", {"user": None})


@app.get("/login", response_class=HTMLResponse)
def login_page(request: Request):
    if get_current_user(request):
        return RedirectResponse("/dashboard", status_code=303)
    return templates.TemplateResponse(request, "login.html", {"user": None})


class SignupBody(BaseModel):
    email: str
    password: str


@app.post("/api/signup")
def api_signup(body: SignupBody, request: Request):
    email = body.email.strip().lower()
    if not EMAIL_RE.match(email):
        return JSONResponse({"error": "that doesn't look like a valid email"}, status_code=400)
    if len(body.password) < 8:
        return JSONResponse({"error": "password must be at least 8 characters"}, status_code=400)

    with get_session() as session:
        if session.query(User).filter_by(email=email).first():
            return JSONResponse({"error": "an account with that email already exists"}, status_code=400)

        is_admin = bool(ADMIN_EMAIL) and email == ADMIN_EMAIL
        user = User(email=email, password_hash=hash_password(body.password), is_admin=is_admin)
        session.add(user)
        session.commit()
        session.refresh(user)
        request.session["user_id"] = user.id

    return {"ok": True}


class LoginBody(BaseModel):
    email: str
    password: str


@app.post("/api/login")
def api_login(body: LoginBody, request: Request):
    email = body.email.strip().lower()
    with get_session() as session:
        user = session.query(User).filter_by(email=email).first()
        if not user or not verify_password(body.password, user.password_hash):
            return JSONResponse({"error": "invalid email or password"}, status_code=401)
        request.session["user_id"] = user.id

    return {"ok": True}


@app.post("/api/logout")
def api_logout(request: Request):
    request.session.clear()
    return RedirectResponse("/", status_code=303)


# ---------- dashboard ----------

@app.get("/dashboard", response_class=HTMLResponse)
def dashboard(request: Request):
    user = get_current_user(request)
    if not user:
        return RedirectResponse("/login", status_code=303)

    with get_session() as session:
        forge = session.query(ForgeProfile).filter_by(user_id=user["id"]).first()
        forge_data = None
        steps_data = []
        if forge:
            forge_data = {"goal": forge.goal, "business_model": forge.business_model, "time_available": forge.time_available}
            steps = session.query(ForgeStep).filter_by(user_id=user["id"]).order_by(ForgeStep.step_index).all()
            steps_data = [{"id": s.id, "title": s.title, "description": s.description, "status": s.status} for s in steps]

    return templates.TemplateResponse(request, "dashboard.html", {"user": user, "forge": forge_data, "steps": steps_data})


class ForgeBody(BaseModel):
    goal: str
    business_model: str
    time_available: str


@app.post("/api/forge")
def api_forge(body: ForgeBody, request: Request):
    user = get_current_user(request)
    if not user:
        return JSONResponse({"error": "not logged in"}, status_code=401)

    with get_session() as session:
        existing = session.query(ForgeProfile).filter_by(user_id=user["id"]).first()
        if existing:
            return JSONResponse({"error": "your Forge is already set up"}, status_code=400)

        business_model = body.business_model.strip()[:120]
        session.add(ForgeProfile(
            user_id=user["id"],
            goal=body.goal.strip()[:120],
            business_model=business_model,
            time_available=body.time_available.strip()[:60],
        ))

        for i, (title, description) in enumerate(get_template(business_model)):
            session.add(ForgeStep(
                user_id=user["id"],
                step_index=i,
                title=title,
                description=description,
                status="current" if i == 0 else "todo",
            ))

        session.commit()

    return {"ok": True}


@app.post("/api/forge/steps/{step_id}/complete")
def api_forge_step_complete(step_id: int, request: Request):
    user = get_current_user(request)
    if not user:
        return JSONResponse({"error": "not logged in"}, status_code=401)

    with get_session() as session:
        step = session.get(ForgeStep, step_id)
        if not step or step.user_id != user["id"]:
            return JSONResponse({"error": "step not found"}, status_code=404)
        if step.status == "done":
            return JSONResponse({"error": "already complete"}, status_code=400)

        step.status = "done"
        next_step = (
            session.query(ForgeStep)
            .filter_by(user_id=user["id"], status="todo")
            .order_by(ForgeStep.step_index)
            .first()
        )
        if next_step:
            next_step.status = "current"
        session.commit()

    return {"ok": True}


# ---------- account ----------

@app.get("/account", response_class=HTMLResponse)
def account_page(request: Request):
    user = get_current_user(request)
    if not user:
        return RedirectResponse("/login", status_code=303)
    return templates.TemplateResponse(request, "account.html", {"user": user})


class PasswordChangeBody(BaseModel):
    current_password: str
    new_password: str


@app.post("/api/account/password")
def api_account_password(body: PasswordChangeBody, request: Request):
    user = get_current_user(request)
    if not user:
        return JSONResponse({"error": "not logged in"}, status_code=401)
    if len(body.new_password) < 8:
        return JSONResponse({"error": "new password must be at least 8 characters"}, status_code=400)

    with get_session() as session:
        db_user = session.get(User, user["id"])
        if not verify_password(body.current_password, db_user.password_hash):
            return JSONResponse({"error": "current password is incorrect"}, status_code=401)
        db_user.password_hash = hash_password(body.new_password)
        session.commit()

    return {"ok": True}


# ---------- admin ----------

@app.get("/admin", response_class=HTMLResponse)
def admin(request: Request):
    user = get_current_user(request)
    if not user or not user["is_admin"]:
        return RedirectResponse("/login", status_code=303)

    with get_session() as session:
        user_count = session.scalar(select(func.count()).select_from(User)) or 0
        waitlist_count = session.scalar(select(func.count()).select_from(WaitlistSignup)) or 0
        users = session.query(User).order_by(User.created_at.desc()).limit(50).all()
        waitlist = session.query(WaitlistSignup).order_by(WaitlistSignup.created_at.desc()).limit(50).all()
        users_data = [{"email": u.email, "created_at": u.created_at, "is_admin": u.is_admin} for u in users]
        waitlist_data = [{"email": w.email, "building": w.building, "created_at": w.created_at} for w in waitlist]

    return templates.TemplateResponse(request, "admin.html", {
        "user": user,
        "user_count": user_count,
        "waitlist_count": waitlist_count,
        "users": users_data,
        "waitlist": waitlist_data,
    })
