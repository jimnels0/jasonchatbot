# CLAUDE.md: jasonchatbot (AI website chat for other companies)

Product plan, features, pricing and marketing: `jasonchatbot.md`. This file is how the code works and how to run it.
Product name is NOT decided: the placeholder "Jason Chatbot" lives in one constant, `APP_NAME` in `web/src/i18n.ts`.

## Where it runs (v0.1, 2026-11-21)
- **Temp domain:** https://bot.pennybiddeals.com (Cloudflare A record `bot` -> 108.181.184.22, proxied; the Penny
  wildcard origin cert covers it). Replace with the real domain later: CloudPanel site + Cloudflare + `vite.config.ts` proxy + widget docs.
- **Server:** jasonvps (CloudPanel, PHP 8.5, MySQL 8.4 Percona). Site user `jcbot`, web root `/home/jcbot/htdocs/bot.pennybiddeals.com`,
  database `jcbot`. **jasonvps is also the live trading machine: never touch other sites, crons or services.**
- **Config:** `/home/jcbot/jcb-config.php` (OUTSIDE the web root, mode 600, never in git): `$db_host/$db_name/$db_user/$db_pass`,
  AI keys (`$anthropic_api_key`, `$groq_api_key`, `$cerebras_api_key`, `$openai_api_key`), optional `$ai_chain`,
  `$demo_tenant_key` (company whose bot answers on the landing page), `$geo_block_disabled`.
- **One-time root setup:** `sudo bash ~/penny-move/jcb_setup.sh` on jasonvps (James runs it; Claude has no sudo there).
  Creates the site, DB, config, nginx rules (`/api/lib/` and `worker.php|migrate.php` return 404), deploy key, pdftotext, worker cron.
- **Worker cron:** `/etc/cron.d/jasonchatbot-worker`, every minute: `php api/worker.php` reads queued websites/PDFs.

## Deploy
`bash scripts/deploy.sh` (typecheck, Vite build, PHP lint, upload as `jcbot` via SSH host `jcb`, rsync into the web root,
`php api/migrate.php`, check gate.php). `--skip-build` to reuse `web/dist`. Make yourself platform admin once:
`ssh jcb "php ~/htdocs/bot.pennybiddeals.com/api/migrate.php --admin jimnels0@gmail.com"` (after signing up).

## Code map
- `api/lib/core.php` config, PDO, JSON/CORS, `client_ip()`, `geo()` (ip-api, cached in `geo_cache`), `rate_ok()` (DB `rate_hits`).
- `api/lib/schema.php` all tables (CREATE IF NOT EXISTS; `migrate.php` runs them). Every customer row has `tenant_id`.
- `api/lib/auth.php` sessions (sha256 token, `X-Auth-Token`), roles owner/agent, `is_platform_admin`.
- `api/lib/knowledge.php` crawler (sitemap or links, SSRF guard: public IPs only, pinned DNS, ports 80/443, redirects re-checked),
  HTML->text, chunking (~1200 chars), PDF via `pdftotext`, FULLTEXT search (`kb_search`, `qa_search`).
- `api/lib/answer.php` one answer: monthly limit -> approved answers + knowledge -> model -> JSON {answer, answered, handoff, topic, sources}.
- `api/lib/ai.php` provider chain (Anthropic first, then Groq/Cerebras/OpenAI), first valid JSON wins.
- Endpoints: `widget.php` (public: config/start/say/poll/human), `auth.php`, `chats.php` (inbox + take over),
  `sources.php`, `qa.php`, `tenant.php`, `team.php`, `stats.php`, `gate.php` (outside-USA), `owner.php` (platform console).
- `web/` React dashboard + landing (hash routes), `web/public/widget.js` the one-line widget (Shadow DOM, EN/ES, polls for agent replies).

## Rules followed (from James's global rules)
EN+ES strings (`web/src/i18n.ts`), themed dialogs only (`useDialog`, no alert/confirm), name/company Title Case in browser AND server
(`format.ts` / `lib/format.php`), Suggest a strong password on sign-up + change password, first-visit cookie notice,
outside-USA gate for the dashboard site with blocked visits logged (widget not gated: customers' visitors), never put secrets in git.

## Tests
`php tests/lib_test.php` (needs mbstring, run on jasonvps): formatting, link resolving, SSRF guard, HTML->text, chunking. 33 checks.

## Not built yet (next)
Stripe billing (plans are set by hand in the Owner console), emails (invites, password reset, handoff alerts: needs a sender),
vector search, streamed answers, 2FA, CSV export, browser tests (Playwright), Terms/Privacy are DRAFTS for the lawyer.
