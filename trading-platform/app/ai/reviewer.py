import json

from anthropic import Anthropic

from app.config import config

SYSTEM_PROMPT = """You are a risk-review gate for a personal, paper-trading crypto bot. \
You do not generate trade ideas - a rules-based scanner already has. Your only job is to \
sanity-check ONE candidate trade before it is (paper) executed, and decide whether it should \
proceed.

Reject trades where: the stated reasoning is weak or contradicts the price data given, the \
position size looks unreasonable, or recent price action shown suggests the setup is already \
invalidated. Approve trades where the rules-based reasoning is sound and consistent with the \
data provided.

This is a paper trading account with virtual funds only - be a genuine, critical risk filter, \
not a rubber stamp, but you are not exposing anyone to real financial loss.

Respond with ONLY a JSON object, no other text, in exactly this shape:
{"approve": true or false, "confidence": 0.0-1.0, "reasoning": "one or two sentences"}"""


def review_signal(strategy: str, symbol: str, side: str, price: float, reason: str, recent_prices: list[float]) -> tuple[bool, float, str]:
    if not config.anthropic_api_key:
        return True, 0.5, "AI review skipped: no ANTHROPIC_API_KEY configured, auto-approving."

    client = Anthropic(api_key=config.anthropic_api_key)
    user_prompt = (
        f"Strategy: {strategy}\n"
        f"Symbol/path: {symbol}\n"
        f"Proposed side: {side}\n"
        f"Signal price: {price}\n"
        f"Scanner's stated reasoning: {reason}\n"
        f"Recent close prices (oldest to newest): {recent_prices}\n\n"
        "Should this trade be approved?"
    )

    try:
        response = client.messages.create(
            model=config.claude_model,
            max_tokens=300,
            system=SYSTEM_PROMPT,
            messages=[{"role": "user", "content": user_prompt}],
        )
        raw_text = "".join(block.text for block in response.content if block.type == "text").strip()
        parsed = json.loads(raw_text)
        return bool(parsed["approve"]), float(parsed.get("confidence", 0.5)), str(parsed.get("reasoning", ""))
    except Exception as exc:  # noqa: BLE001 - any AI/network failure should fail safe (reject), not crash the loop
        return False, 0.0, f"AI review failed ({type(exc).__name__}: {exc}), rejecting as a precaution."
