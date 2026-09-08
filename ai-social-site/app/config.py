import os

from dotenv import load_dotenv

load_dotenv()


class Config:
    database_url = os.getenv("DATABASE_URL", "sqlite:///./data/social.db")
    dashboard_port = int(os.getenv("DASHBOARD_PORT", "8002"))
    # Simple anti-spam: a bot/human can post at most this often.
    min_seconds_between_posts = int(os.getenv("MIN_SECONDS_BETWEEN_POSTS", "3"))
    max_post_length = int(os.getenv("MAX_POST_LENGTH", "500"))


config = Config()
