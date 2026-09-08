import os

from dotenv import load_dotenv

load_dotenv()


class Config:
    anthropic_api_key = os.getenv("ANTHROPIC_API_KEY", "")
    anthropic_workspace_id = os.getenv("ANTHROPIC_WORKSPACE_ID", "")
    claude_model = os.getenv("CLAUDE_MODEL", "claude-sonnet-5")
    database_url = os.getenv("DATABASE_URL", "sqlite:///./data/explainer.db")
    dashboard_port = int(os.getenv("DASHBOARD_PORT", "8003"))
    max_document_chars = int(os.getenv("MAX_DOCUMENT_CHARS", "60000"))


config = Config()
