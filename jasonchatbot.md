# JasonChatbot: AI customer chat for small and mid-size businesses

Development and marketing guide, v1.0 plan. Written 2026-11-20.
Working name only: pick a product name and domain before any marketing work.

---

## 1. What it is (one paragraph)

A chat bubble that any company can paste onto its website (like Zoho SalesIQ, Intercom or Tidio),
but the first person who answers is an AI that has read the company's website, manuals, product
guides, price lists and FAQs. It answers questions in the company's own voice, cites where the answer
came from, collects leads, and hands the conversation to a real person when it cannot help or the
visitor asks for a human. The company sets it up in under 30 minutes: paste a website address,
upload some PDFs, copy one line of script onto its site.

**Who buys it:** businesses with 1-200 employees that get repeat questions online and cannot staff a
chat window all day: home services, dental and medical offices, law firms, property managers, auto
dealers, e-commerce stores, gift card and auction sites (Penny is customer #1 and the test bed).

**Why they switch:** Zoho/Tidio-style chat needs a human online or it is just a contact form. Big AI
tools (Intercom Fin and similar) charge per resolution and are built for large support teams. We sit in
between: flat monthly price, AI-first, set up in an afternoon, a person can always take over.

---

## 2. The 20 features for v1.0

Each feature lists what it does and the rule that makes it safe or sellable.

### Getting the bot to know the company

**1. Website crawler.** Client types its website address; we crawl up to N pages (plan limit),
extract the readable text, skip login pages, honour robots.txt, and re-crawl on a schedule
(weekly by default, "re-crawl now" button). Each page is stored with its URL so answers can link to it.

**2. Document upload.** PDF, Word (.docx), plain text, Markdown, CSV and Excel price lists. Text is
extracted, split into chunks of ~500-800 tokens with overlap, and stored with file name and page
number. Scanned PDFs go through OCR. Per-plan storage limits.

**3. Q&A pairs (exact answers).** The client writes "question → answer" pairs for things that must be
said word for word (prices, hours, return policy, legal wording). A matching saved answer wins over
everything else and costs no AI call. (Proven on Penny: `assistant_qa` answered 57% of questions with
no model call.)

**4. Company profile and voice.** Business name, what they sell, service area, hours, phone, address,
tone ("friendly and casual" / "formal"), words to avoid, and topics the bot must never discuss
(medical advice, legal advice, competitor pricing). This becomes the fixed part of the system prompt.

**5. Answers with sources (RAG).** For every question: find the best-matching chunks (vector search +
keyword search combined), send only those to the AI with the company profile, and answer **only from
that material**. Each answer shows small "Source: Shipping policy (page 3)" links. If nothing relevant
is found, the bot says it does not know and offers a human or a callback. No made-up prices, ever.

### The chat the visitor sees

**6. Embeddable widget.** One `<script>` line. Loads fast (under 50 KB, served from a CDN), lives in a
Shadow DOM so the client's CSS cannot break it, works on phones, streams the answer word by word,
remembers the conversation for the visit. Colours, logo, greeting, bubble position and launcher icon
set from the dashboard.

**7. Allowed domains.** The widget only runs on websites the client lists (checked on the server from
the page origin, not trusted from the browser). Stops other people copying the script and using the
client's AI credits. Clear error in the dashboard when a domain is missing (lesson from the Zoho
bubble that silently refused to load on pennybiddeals.com).

**8. Languages.** The bot answers in the visitor's language (detected from the message, with a
language switch in the widget). v1 ships English and Spanish for the widget text and dashboard;
the AI itself can answer in most languages from English source material.

**9. Hand off to a human (live chat).** Visitor clicks "Talk to a person" or the bot detects it is
stuck. If an agent is online, the chat moves to the agent inbox with the full history; if not, the bot
collects name, email/phone and the question and promises a reply (business hours shown).

**10. Lead capture.** The client chooses when to ask for contact details (before chat, after 2
questions, only on hand-off). Leads go to the dashboard, an email notification and a CSV export.
Names, phones and addresses are formatted (capitalised, phone as (555) 123-4567) on the browser and
again on the server.

### The client's dashboard

**11. Agent inbox.** Live list of conversations, take over from the bot, reply in real time, canned
replies, mark solved, assign to a teammate. Desktop and phone browser. Sound and browser notification
on new hand-offs.

**12. Conversation history and search.** Every conversation stored with the sources used, searchable,
filter by date / rated bad / handed off / lead captured. Transcript emailed to the visitor on request.

**13. Teach the bot from mistakes.** Thumbs up/down on each bot answer (visitor) and "fix this answer"
button (client). A fix becomes a Q&A pair (feature 3) in one click, so the same mistake never repeats.

**14. Analytics.** Conversations per day, % answered by the bot without a human, top 20 questions,
questions the bot could not answer (the to-do list for new content), leads, average rating, AI cost
this month. Simple charts, CSV export.

**15. Team and roles.** Owner, admin, agent. Invite by email, remove, reset password, two-factor
sign-in (TOTP app) and "suggest a strong password" on every password screen. Agents only see the inbox.

### The business side

**16. Plans, billing and usage limits.** Stripe subscriptions (monthly / yearly), free 14-day trial,
plan limits on AI answers per month, pages crawled, document storage and seats. Warnings at 80% and
100%; at the limit the bot switches to "leave a message" mode instead of failing. Overage packs.

**17. Multi-tenant isolation.** Every row in the database carries the client's tenant id and the
database enforces it (row-level security), so one company can never see another company's chats or
documents, even through a bug in our code. Every document chunk sent to the AI comes from that
tenant only.

**18. Guardrails and abuse control.** Rate limits per visitor IP and per tenant; prompt-injection
defence (documents and visitor text are treated as data, never as instructions); topic blocks from
feature 4; maximum answer length; profanity and spam handling; blocked-visitor list. Bot never claims
to be a human.

**19. AI provider routing and cost control.** One main model with a fallback provider if it errors or
runs out of quota (same design as Penny's Admin > AI providers: priority list, encrypted keys, alerts
by email when a provider fails or runs out of credit). Answer cache for repeated generic questions.
Per-tenant token log (provider, tokens in/out, cost; never the question text in the cost log).

**20. Privacy and compliance basics.** Cookie notice in the widget (essential only), privacy policy and
data processing terms for clients, delete a visitor's data on request, delete everything when a client
cancels (after 30 days), choose to not store transcripts at all, encrypted backups, US data hosting.
EN + ES legal pages reviewed by a lawyer before launch.

### Not in v1 (park for v1.1+)
Facebook Messenger / WhatsApp / SMS channels, email ticketing, Shopify and WordPress plugins (v1 uses
the script tag, which already works on both), voice, booking appointments into calendars, CRM sync
(HubSpot, Salesforce), white-label reseller accounts, custom AI actions (look up an order status
through the client's API), self-hosted option.

---

## 3. Technology stack

Chosen for: one language end to end, streaming chat, 1,000 client companies on modest hardware,
and a solo developer plus Claude doing the building.

| Part | Choice | Why |
|---|---|---|
| Language | **TypeScript** everywhere (Node.js 22 LTS on the server) | One language for widget, dashboard and server; first-class Anthropic SDK (`@anthropic-ai/sdk`); good at many open streaming connections |
| API server | **Fastify** (or NestJS if we want more structure) | Fast, small, good SSE/WebSocket support |
| Dashboard | **React + Vite + Tailwind** | Same as Penny, Timesheets; known patterns, themed dialogs, EN/ES strings |
| Widget | **Vanilla TypeScript**, built with Vite to one small file, Shadow DOM | Must load fast on any site and not depend on the client's framework |
| Real-time | **Server-Sent Events** for streaming answers; **WebSocket** for the agent inbox | SSE is simplest for one-way streaming; inbox needs two-way |
| Database | **PostgreSQL 16** with **pgvector** (HNSW index) | One database for tenants, chats, users and vector search; row-level security per tenant; no extra vector service to pay for until we outgrow it |
| Cache / queue | **Redis** + **BullMQ** | Rate limits, sessions, answer cache, and background jobs (crawling, document parsing, embedding, emails) |
| File storage | **Amazon S3** | Uploaded documents and crawl snapshots, encrypted, per-tenant prefixes |
| AI answers | **Anthropic Claude** via the official SDK (section 4) | Strong at grounded answers with citations, prompt caching keeps cost down |
| Embeddings | A dedicated embedding model (Anthropic does not sell one; Voyage AI is the common pairing; check current price and pick before building) | Turns chunks and questions into vectors for search |
| Payments | **Stripe Billing** | Subscriptions, trials, invoices, card storage handled by Stripe |
| Email | Amazon SES (or Postmark) from our own domain, never a personal Gmail | Transactional mail: invites, transcripts, lead alerts |
| Auth | Our own sessions in Postgres + TOTP 2FA; Google sign-in later | Same pattern as Penny |
| Hosting | **AWS** (partner preference), us-east or us-west | See section 5 |
| CDN / protection | **Cloudflare** in front of everything | Widget script cached worldwide, DDoS protection, origin hidden |
| Monitoring | Uptime monitor + error tracking (Sentry) + CloudWatch alarms | Know before clients do |
| Tests | Vitest (unit), Playwright (widget and dashboard in a real browser), an answer-quality eval set | Same rule as Penny: no native alert/confirm, guard test from day one |

**Why not PHP + MySQL like Penny:** it can be done, but streaming answers to hundreds of open chats at
once, WebSockets for the inbox, and background workers are all easier and cheaper in Node. MySQL has
no built-in vector search on the versions we use; Postgres + pgvector does it in the same database.

---

## 4. AI design

### Models (Anthropic, prices per million tokens, checked 2026-11-20; re-check before launch)

| Job | Model | ID | Input / output |
|---|---|---|---|
| Answering visitors (default) | Claude Opus 5.5 at **low** effort | `claude-opus-5-5` | $4 / $20 (cached input reads $0.20) |
| Cheaper plan option | Claude Sonnet 5.5 | `claude-sonnet-5-5` | $2 / $10 |
| Small jobs: detect language, "does this need a human?", rewrite the question for search, title a conversation | Claude Haiku 5.5 | `claude-haiku-5-5` | $0.10 / $0.50 |

Start with Opus 5.5 at low effort for answers and measure quality and cost on a real eval set
(feature 13 data) before deciding whether lower plans use Sonnet. Use the most capable model that the
margin allows; a wrong answer costs a client more than a few cents.

### How one answer is built
1. Check saved Q&A pairs (exact or close match) → answer instantly, no AI call.
2. Check the per-tenant answer cache for a generic repeat question → answer, no AI call.
3. Haiku: detect language, rewrite the question into a search query, flag "wants a human".
4. Search the tenant's chunks (vector + keyword), keep the best 5-8.
5. Call Claude with: fixed system prompt (rules) → company profile (cached) → retrieved chunks as
   documents with citations turned on → the conversation so far → the new question. Stream the reply.
6. If nothing relevant was found, or Claude says it is not sure: offer a human / take a message.
7. Log tokens and cost per tenant. Store sources with the message.

### Rules that keep cost down
- **Prompt caching:** the system prompt and company profile go first and never change between
  requests, so they are billed at the cached rate after the first question.
- **Short context:** only the top chunks, not the whole website.
- **Limits:** max answer length, max conversation turns before suggesting a human, monthly cap per plan.
- **Batch jobs** (re-crawls, re-embedding, nightly summaries) use the Batch API at half price.

### Rough cost per answer (estimate, not measured)
About 6,000 input tokens (mostly cached profile + 6 chunks) and 300 output tokens:
- Opus 5.5: roughly **$0.01-0.03** per answer depending on cache hits and thinking tokens.
- Haiku helper calls: a fraction of a cent.
A client with 1,500 bot answers a month costs us roughly $15-45 in AI. Measure in the beta before
setting prices.

---

## 5. Infrastructure for 1,000 client companies

### Size the load first (assumptions, adjust after beta)
- 1,000 clients × average 50 chats/day × 5 bot answers per chat = **250,000 answers/day**
- ≈ 3 answers/second on average, plan for **30/second at peak**
- Documents: 1,000 clients × ~2,000 chunks = **2 million vectors** (pgvector with HNSW handles this
  on one good Postgres server)
- Most of the money is AI tokens, not servers.

### Layout (AWS)
```
Visitors ──► Cloudflare ──► Load balancer (ALB)
                              ├── API servers (2+ containers, auto-scaling)   ← chat, dashboard API, SSE, WebSocket
                              └── Dashboard + widget files (S3 + Cloudflare cache)
             Workers (1+ containers)  ← crawling, PDF parsing, embeddings, emails, Stripe webhooks
             RDS PostgreSQL 16 + pgvector (Multi-AZ, automated backups, point-in-time restore)
             ElastiCache Redis (rate limits, cache, BullMQ queues, WebSocket fan-out)
             S3 (documents, encrypted)   SES (email)   CloudWatch + Sentry (alerts)
```
- Run the API and workers as Docker containers on **ECS Fargate** (no servers to patch). Start with
  2 small API tasks + 1 worker; auto-scale on CPU and open connections.
- **Stateless API servers**: nothing kept in local files, so any server can take any request and more
  can be added behind the load balancer (the problem Penny would have with its temp-file cache).
- Background jobs never run on the API servers.
- One scheduled job runner only (no double runs).

### Starting small
Before 50 paying clients this can all run on **one** EC2 instance (8 GB RAM) with Docker Compose
(Postgres + Redis + API + worker) plus S3 and nightly encrypted backups to S3 and Dropbox. Move to the
layout above when clients pass ~50 or the box passes 60% CPU. Same code either way.

### Backups and recovery
RDS automated backups (35 days) + nightly encrypted dump to a second location. Test a full restore
every quarter. Written recovery steps in this repo.

### Rough monthly running cost (estimate)
- Starting (1 instance, S3, backups): about $80-150/month
- 1,000 clients layout (ECS, RDS Multi-AZ, Redis, ALB, S3, CloudWatch): about $800-1,500/month
- AI tokens: the large, variable cost; covered by plan prices and limits

---

## 6. Data model (main tables)

`tenants` (company, plan, status, allowed domains, profile, voice, widget settings) ·
`users` (dashboard users, role, tenant) · `sessions` · `sources` (website / file / Q&A, status, last
crawled) · `chunks` (tenant, source, text, page/url, embedding vector) · `qa_pairs` ·
`conversations` (tenant, visitor id, language, status: bot / waiting / human / closed, rating) ·
`messages` (role, text, sources used, tokens, cost) · `leads` · `handoffs` · `usage_monthly` ·
`ai_log` (provider, model, tokens, cost; no text) · `invoices` (mirror of Stripe) · `audit_log`.
Every table except global settings has `tenant_id` and a row-level security policy.

---

## 7. Security checklist (v1 must-haves)

- Tenant isolation enforced by the database (feature 17); automated test that tries to read another
  tenant's data and must fail.
- Widget checks the page origin against allowed domains on the server.
- API keys and Stripe secrets only on the server, encrypted at rest; never in git.
- Visitor and document text never treated as instructions to the AI.
- Rate limits on every public endpoint; login lockout after failed attempts.
- 2FA for dashboard users; session tokens, not ids from the request.
- All mail from our own domain.
- Outside-USA rule: dashboard sign-up limited to US businesses at launch; the widget itself must work
  for any visitor of a client's site (do NOT geo-block the widget).
- Lawyer-reviewed Terms, Privacy Policy and Data Processing Agreement before taking money.

---

## 8. Build plan (order of work)

| Phase | Weeks (rough) | Done when |
|---|---|---|
| 0. Foundations | 1-2 | Repo, Docker Compose, Postgres + pgvector, auth, tenants, CI tests |
| 1. Knowledge | 2-3 | Crawl a site, upload PDFs, chunks + embeddings stored, search returns good chunks |
| 2. Answering | 2-3 | RAG answers with sources, saved Q&A, cache, provider fallback, cost log, eval set of 100 questions |
| 3. Widget | 2 | Script tag on Penny dev, streaming, branding, allowed domains, EN/ES |
| 4. Dashboard | 3 | Inbox + hand-off, history, fix-this-answer, analytics, team roles |
| 5. Business | 2 | Stripe plans and limits, trial, emails, legal pages, cookie notice |
| 6. Beta | 4 | Penny + 5-10 friendly businesses free; measure cost per answer, answer quality, hand-off rate |
| 7. Launch | - | Prices set from beta numbers, marketing site live |

Penny Bid Deals replaces its Zoho widget with this as the first real customer.

---

## 9. Marketing guide

### Positioning (one line)
"Your website's front desk that never sleeps: it has read everything about your business, answers in
seconds, and calls a person in when it should."

### Who to sell to first
1. Local service businesses (plumbing, HVAC, roofing, pest control): after-hours questions and quotes.
2. Professional offices (law firms, dental, accounting): intake questions, hours, "do you handle X".
3. Property managers and real estate: availability, rent, application steps.
4. Small e-commerce: shipping, returns, sizing, order questions.
5. Our own network: James's other sites and contacts (warm leads, case studies).

### Pricing (draft, finalise after beta cost data)
| Plan | Price / month | Includes |
|---|---|---|
| Starter | $39 | 1 website, 500 AI answers, 100 pages, 50 MB docs, 1 seat, our branding on the widget |
| Business | $99 | 2,000 answers, 1,000 pages, 500 MB, 3 seats, remove branding, live chat hand-off, analytics |
| Pro | $249 | 7,500 answers, 5,000 pages, 2 GB, 10 seats, priority support, Spanish dashboard, API access (v1.1) |
| Extra answers | $10 per 500 | Bought in the dashboard |
14-day free trial, no card. Yearly = 2 months free. Flat price, no per-resolution fees (our main
difference from the big AI support tools).

### What to say (benefits, not features)
- "Answers 70%+ of questions without you" (prove this number in beta before using it).
- "Set up in 30 minutes: paste your website, upload your PDFs, copy one line."
- "Never makes things up: every answer links to where it came from, or it takes a message."
- "A real person can jump in anytime."
- "Speaks Spanish too."
- "Flat monthly price. No surprise bills."

### Channels
- Marketing website with a live demo bot trained on the marketing site itself (the product sells itself).
- "Try it on your website" box: visitor types their URL, we crawl 20 pages and show a working demo in
  2 minutes (the strongest sales tool; rate-limit it hard).
- Google Ads on "website chatbot for [industry]", "AI live chat", "Zoho SalesIQ alternative",
  "Tidio alternative".
- Short screen-recording videos per industry (YouTube, Facebook, LinkedIn).
- Partner with web designers and agencies: 20% recurring referral commission.
- Listings: G2, Capterra, Product Hunt launch, WordPress plugin directory (v1.1).
- Case study from Penny and each beta client (before/after: questions answered, leads captured).

### Sales assets to make
Landing page, pricing page, demo video (2 min), industry one-pagers, comparison pages (vs Zoho,
Tidio, Intercom; facts only, dated), setup guide, help centre (answered by our own bot), onboarding
emails (day 0, 2, 7, 12 of trial).

### Metrics to watch
Trial-to-paid %, time to first answer after sign-up, % answered by bot, hand-off rate, bad-answer
rate, AI cost per client vs plan price (keep AI under 30% of revenue), churn, referral sign-ups.

---

## 10. Decisions still open

1. Product name and domain.
2. Final pricing after the beta measures real cost per answer.
3. Default answer model per plan (Opus 5.5 low effort everywhere, or Sonnet 5.5 on Starter): decide
   from the eval set, not guesses.
4. Embedding provider (check current price and quality, pick one, store which model made each vector).
5. Fargate vs plain EC2 at the 50-client mark.
6. Whether the partner hosts / co-owns it, and who supports clients.
7. Legal: Terms, Privacy, DPA, AI disclosure wording (lawyer).
