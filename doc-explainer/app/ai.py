import json

from anthropic import Anthropic

from app.config import config

SYSTEM_PROMPT = """You are a careful, skeptical reviewer of contracts, leases, terms of service, and \
other agreements. You are NOT a lawyer and this is NOT legal advice - the person using this tool \
knows that, so don't caveat every sentence with it, just don't overstate your authority.

Read the document text the user provides and produce a concrete, specific analysis - reference \
actual clauses from the text, don't give generic advice that could apply to any document.

Respond with ONLY a JSON object, no other text, in exactly this shape:
{
  "document_type": "short description of what kind of document this is",
  "overall_risk": "low" | "medium" | "high",
  "summary": "2-4 sentence plain-English summary of what this document actually commits the reader to",
  "flagged_clauses": [
    {"quote": "a short verbatim excerpt from the document", "explanation": "why this matters, in plain English", "severity": "low" | "medium" | "high"}
  ],
  "questions_to_ask": ["specific, pointed questions the reader should ask the other party before agreeing"]
}

Flag genuinely unusual, one-sided, or consequential clauses - not routine boilerplate. If the \
document is short or low-risk, it's fine to return few or zero flagged_clauses. Quote clauses \
verbatim and briefly (a sentence or phrase, not whole paragraphs)."""


def analyze_document(text: str) -> dict:
    if not config.anthropic_api_key:
        return {
            "document_type": "unknown",
            "overall_risk": "unknown",
            "summary": "No ANTHROPIC_API_KEY configured - cannot analyze.",
            "flagged_clauses": [],
            "questions_to_ask": [],
        }

    extra_headers = {"anthropic-workspace-id": config.anthropic_workspace_id} if config.anthropic_workspace_id else {}
    client = Anthropic(api_key=config.anthropic_api_key, default_headers=extra_headers)

    try:
        response = client.messages.create(
            model=config.claude_model,
            max_tokens=3000,
            system=SYSTEM_PROMPT,
            messages=[{"role": "user", "content": f"Document to analyze:\n\n{text}"}],
        )
        raw_text = "".join(block.text for block in response.content if block.type == "text").strip()
        return json.loads(raw_text)
    except Exception as exc:  # noqa: BLE001 - surface any failure to the UI instead of crashing
        return {
            "document_type": "error",
            "overall_risk": "unknown",
            "summary": f"Analysis failed: {type(exc).__name__}: {exc}",
            "flagged_clauses": [],
            "questions_to_ask": [],
        }
