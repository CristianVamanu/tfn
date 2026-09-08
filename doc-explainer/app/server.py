import json
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel
from sqlalchemy import select

from app.ai import analyze_document
from app.config import config
from app.db import Analysis, get_session, init_db

app = FastAPI(title="plain english")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))

init_db()


def _serialize(a: Analysis) -> dict:
    return {
        "id": a.id,
        "created_at": a.created_at.isoformat(),
        "title": a.title,
        "result": json.loads(a.result_json),
    }


@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    with get_session() as session:
        history = session.scalars(select(Analysis).order_by(Analysis.id.desc()).limit(30)).all()
        history_rows = [{"id": a.id, "created_at": a.created_at.isoformat(), "title": a.title} for a in history]
    return templates.TemplateResponse(request, "index.html", {"history_json": json.dumps(history_rows)})


class AnalyzeBody(BaseModel):
    title: str = "untitled document"
    text: str


@app.post("/api/analyze")
def api_analyze(body: AnalyzeBody):
    text = body.text.strip()
    if not text:
        return JSONResponse({"error": "paste some document text first"}, status_code=400)
    text = text[: config.max_document_chars]

    result = analyze_document(text)

    with get_session() as session:
        a = Analysis(title=(body.title or "untitled document").strip()[:120], input_text=text, result_json=json.dumps(result))
        session.add(a)
        session.commit()
        session.refresh(a)
        return _serialize(a)


@app.get("/api/history")
def api_history():
    with get_session() as session:
        rows = session.scalars(select(Analysis).order_by(Analysis.id.desc()).limit(30)).all()
        return [{"id": a.id, "created_at": a.created_at.isoformat(), "title": a.title} for a in rows]


@app.get("/api/analysis/{analysis_id}")
def api_get_analysis(analysis_id: int):
    with get_session() as session:
        a = session.get(Analysis, analysis_id)
        if not a:
            return JSONResponse({"error": "not found"}, status_code=404)
        return _serialize(a)
