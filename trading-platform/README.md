# AI Trading Platform (personal, paper trading)

A personal crypto trading bot: rules-based scanners (triangular arbitrage +
support/resistance) generate candidate trades, Claude reviews each one before
it's executed, and everything trades against a **virtual portfolio** backed by
real live market data (no real funds, no real orders - ever, by construction:
`MarketData` only calls public/read-only exchange endpoints).

Two long-running processes, sharing one SQLite database:
- **Scheduler** (`app/scheduler.py`) - scans for signals every `SCAN_INTERVAL_SECONDS`, runs the AI review gate, executes approved trades in the paper broker.
- **Dashboard** (`app/dashboard/server.py`) - a read-only web UI showing balances, positions, trade history, and every signal + AI decision (including rejected ones).

## Local quickstart

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env   # then edit .env - at minimum set ANTHROPIC_API_KEY

python -m app.scheduler          # terminal 1: the bot loop
uvicorn app.dashboard.server:app --reload --port 8001   # terminal 2: dashboard
```

Visit `http://localhost:8001`.

## Deploying on the VPS

Assumes Python 3.11+ available (`python3 --version`).

```bash
# 1. Create a dedicated, unprivileged user to run the service
useradd -r -m -d /opt/trading-platform -s /usr/sbin/nologin trading

# 2. Clone the code
git clone -b claude/creatorpulse-hostinger-install-9fmq0w \
  https://github.com/CristianVamanu/tfn.git /tmp/tfn-clone
cp -r /tmp/tfn-clone/trading-platform/* /opt/trading-platform/
rm -rf /tmp/tfn-clone

# 3. Python environment
cd /opt/trading-platform
python3 -m venv venv
./venv/bin/pip install -r requirements.txt

# 4. Configure
cp .env.example .env
nano .env    # set ANTHROPIC_API_KEY at minimum; review the rest

# 5. Ownership + first DB init
chown -R trading:trading /opt/trading-platform
sudo -u trading ./venv/bin/python -c "from app.db import init_db; init_db()"

# 6. systemd services
cp deploy/trading-bot.service deploy/trading-dashboard.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now trading-bot trading-dashboard
systemctl status trading-bot trading-dashboard

# 7. nginx - protect the dashboard with basic auth first
apt-get install -y apache2-utils
htpasswd -c /etc/nginx/.trading-htpasswd yourusername
cp deploy/nginx-trading.conf /etc/nginx/sites-available/theforgenetwork.net
ln -sf /etc/nginx/sites-available/theforgenetwork.net /etc/nginx/sites-enabled/theforgenetwork.net
nginx -t && systemctl reload nginx
```

Then visit `http://theforgenetwork.net` and log in with the basic-auth
credentials you set in step 7.

## Logs

```bash
journalctl -u trading-bot -f
journalctl -u trading-dashboard -f
```

## Going live later (real money)

Everything here is deliberately structured so the strategy/AI-review code
never changes when you go live - only `PaperBroker` gets swapped for a real
exchange-execution adapter that signs and sends real orders (which needs real
API keys with trading permission, kept out of this repo/`.env` entirely and
added directly on the server). Until that adapter exists, no code path in
this project can place a real order.
