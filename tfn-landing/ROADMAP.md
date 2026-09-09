# TFN — MVP Roadmap

Current state: a static marketing/waitlist page. Everything in the bento
grid (Forge roadmap, AI advisor, missions, network feed, marketplace,
learning hub) is a labelled product preview, not working product. This is
the feature list to turn it into a real MVP, in build order.

## Phase 0 — Foundations (blocking everything below)
- [ ] Postgres instead of SQLite (concurrent writes, backups, room to grow)
- [ ] Alembic migrations instead of `Base.metadata.create_all`
- [ ] Auth: email/password signup + login, sessions, password reset
- [ ] Cloudflare R2 wired in (`app/storage.py` already scaffolded) for
      avatars first, marketplace files in Phase 2
- [ ] Basic account settings page (email, password, avatar upload)

## Phase 1 — The Forge (the core product loop)
- [ ] Onboarding flow: goal, business model, time available
- [ ] AI-generated phased roadmap (Claude API call, stored per user, not
      regenerated on every page load)
- [ ] Roadmap step tracking: mark done / current / upcoming
- [ ] AI advisor chat scoped to the user's own Forge + roadmap context,
      with persisted conversation history
- [ ] Daily missions derived from the current roadmap phase
- [ ] Real dashboard replacing the current mockup bento cards

## Phase 2 — Network + Marketplace (needs real payment/legal infra first)
- [ ] Build-in-public feed: posts, congrats/reactions, follows
- [ ] Marketplace listings: sellers upload files (R2), set a price
- [ ] Stripe Checkout for one-off marketplace purchases
- [ ] Stripe Connect for seller payouts (or manual payout for MVP if
      Connect onboarding is too heavy to start)
- [ ] File delivery via R2 pre-signed URLs, not public links
- [ ] Refund handling, basic content moderation for listings/posts
- [ ] **Prerequisite, not optional**: ToS, privacy policy, refund policy,
      and a registered business entity before real money moves through
      the marketplace — this is the same line drawn earlier for TFN as a
      paid product vs. personal use

## Phase 3 — Learning Hub + subscriptions
- [ ] Curated learning content, surfaced by the user's current roadmap
      phase (start static/curated, not user-generated)
- [ ] TFN subscription tiers via Stripe Billing, billing portal
- [ ] Email notifications (roadmap nudges, marketplace sales, digest) —
      needs a transactional email provider (Postmark/Resend/SES)

## Phase 4 — Admin + polish
- [ ] Admin login for you: edit landing copy, view/export waitlist,
      moderate network posts and marketplace listings
- [ ] Analytics on the funnel (waitlist → signup → first roadmap step)
- [ ] Rate limiting on public endpoints (`/api/waitlist` included)

## Cloudflare R2 setup (needed before Phase 0's storage item can go live)
1. Cloudflare dashboard → R2 → Create bucket (e.g. `tfn-storage`)
2. R2 → Manage API tokens → Create API token → **Object Read & Write**,
   scoped to that bucket
3. Copy the Account ID, Access Key ID, and Secret Access Key it gives you
4. On the VPS, add them to `/opt/tfn-landing/.env`:
   ```
   R2_ACCOUNT_ID=...
   R2_ACCESS_KEY_ID=...
   R2_SECRET_ACCESS_KEY=...
   R2_BUCKET_NAME=tfn-storage
   ```
5. `sudo systemctl restart tfn-landing` (the service now loads `.env` via
   `EnvironmentFile` — this didn't exist before, so no prior config was
   silently ignored)

`app/storage.py` already has `upload_bytes`, `upload_file`,
`presigned_get_url`, `presigned_put_url`, `delete_object` — nothing calls
them yet since there's no upload feature to attach them to until Phase 0's
avatar upload or Phase 2's marketplace files.
