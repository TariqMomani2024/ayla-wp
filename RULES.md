# Ayla by Promera — Rules & Conventions

Mandatory for any AI or developer working on this project. If a task conflicts with a rule, stop and ask the owner. Do not work around it.

## 1. Product Identity

- Product name: **Ayla by Promera**. This is the only name customers ever see.
- Customer-facing surfaces must never show: `tymzh`, `tymzh.net`, Laravel, OpenAI, n8n, or any internal URL. This covers the WP admin UI, HTML, JS, error messages, emails, and WhatsApp messages.
- Customer-facing UI languages (plugin and admin): Arabic (RTL) and English. Arabic is the default. The **agent itself replies in the patient's language, whatever it is** (see 8.4).

## 2. Architecture (Non-Negotiable)

```
WhatsApp / Meta  ->  WP plugin (relay only)  ->  tymzh.net (the Brain)
WP admin UI      ->  WP plugin (PHP proxy)   ->  tymzh.net (JSON data)
```

- **tymzh.net (Laravel) is the brain.** All logic, AI, prompts, bookings, reminders, email assistant, billing, plan flags, and data storage live here.
- **WordPress plugin is a thin client.** It has two jobs only:
  1. Relay: receive the Meta webhook and forward it to tymzh.net unchanged.
  2. UI: render screens using data fetched from tymzh.net.
- The WP plugin must **not**:
  - create DB tables, custom post types, or store messages, appointments, leads, or logs;
  - contain business logic, prompt text, or AI calls;
  - call OpenAI or Meta APIs directly;
  - cache business data beyond the current request.
- The only data the plugin stores (in `wp_options`) are its connection settings: `domain`, `username`, `password`, `api_key`.
- Outbound WhatsApp messages (AI replies, confirmations, reminders) are sent **by Laravel directly to Meta**, not by WP. Reminders must work even if the WP site is down.

## 3. Golden Rule: Never Break Existing Code

tymzh.net hosts other live products (AutoPost-WP, licensing, MyList, SmartDesigner, income dashboard, etc.).

- **Additive only.** Do not rename, remove, or change the behavior of any existing route, controller, model, migration, service, view, config key, or middleware.
- Never edit existing migrations. Add new ones.
- Do not touch existing routes, including `/login` (the auth fallback), `/license/*`, `/api/license/*`, `/api/posts`, `/api/publish`, `/api/mylist/*`, `/webhooks/*`, `/r/{code}`, and `/admin/*` existing entries.
- Allowed edits to existing files are limited to minimal additive hooks:
  - registering `routes/ayla.php` in `bootstrap/app.php`;
  - adding one sidebar link in `resources/views/admin/layout.blade.php`;
  - adding new keys to `config/services.php` (or a new `config/ayla.php`, preferred).
- Before any change to an existing file, say why it is unavoidable and keep the diff minimal.
- Reuse the existing admin auth (`admin.login`, `auth` + `EnsureAdmin`). Do not modify it.

## 4. tymzh.net (Laravel) Conventions

Every Ayla feature gets its own URL space, controller, service, model, and table. Nothing is shared with other products.

| Item | Convention |
|---|---|
| Routes file | `routes/ayla.php` (separate) |
| Public API prefix | `/api/ayla/v1/*` |
| Admin prefix | `/admin/ayla/*` (inside the existing `auth` + `EnsureAdmin` group, route names `admin.ayla.*`) |
| API controllers | `App\Http\Controllers\Ayla\*` |
| Admin controllers | `App\Http\Controllers\Admin\Ayla\*` |
| Services | `App\Services\Ayla\*` |
| Models | `App\Models\Ayla\*` |
| Tables | prefixed `ayla_` (e.g. `ayla_accounts`, `ayla_conversations`) |
| Views | `resources/views/admin/ayla/*` |
| Config | `config/ayla.php` |
| Jobs / Mail / Notifications | `App\Jobs\Ayla`, `App\Mail\Ayla`, `App\Notifications\Ayla` |
| Tests | `tests/Feature/Ayla`, `tests/Unit/Ayla` |

- Controllers stay thin: validate (Form Request), call a service, return JSON. Business logic lives in `Services/Ayla`.
- Use Laravel's existing versions and conventions from `composer.json`. Do not add dependencies unless necessary, and ask first.
- All API responses are JSON in a consistent envelope: `{ "ok": bool, "data": ..., "error": { "code": "...", "message": "..." } }`.
- Long or slow work (OpenAI calls, sending WhatsApp, reminders, email scanning) runs in queued jobs. Webhook endpoints acknowledge fast.
- Scheduled work (reminders, email scans) is registered via the Laravel scheduler and must be idempotent.

## 5. Accounts, Identity & Access

- Accounts are created **only by the owner in the tymzh admin area** (`/admin/ayla/accounts`). There is no self-registration and no customer login on tymzh.net.
- An account is identified by its **domain** (one domain per account; the admin can change it).
- When an account is created, the admin sets:
  - business info and domain;
  - `username` and `password` (for the WP plugin);
  - a generated `api_key`;
  - the **plan / feature flags**, editable later, including `email_assistant_enabled` (on/off);
  - the **expiry date** (`expires_at`), editable later (see section 5.1).
- The admin copies `domain`, `username`, `password`, and `api_key` into the WP plugin settings page. The customer never visits tymzh.net.
- Every Ayla API request must be authenticated and verified:
  - `Authorization: Bearer <api_key>` plus the account's `domain` (`X-Ayla-Domain`) and the plugin version (`X-Ayla-Plugin-Version`);
  - the request's domain must match the account's registered domain;
  - the account must be active and within plan limits;
  - reject with generic errors (`401` / `403`) that reveal nothing about other accounts.
- **Username and password** are exchanged at `POST /api/ayla/v1/auth/login` for a short-lived dashboard token (`X-Ayla-Token`, `session_ttl_minutes`). Dashboard and settings endpoints require it. The webhook endpoint needs only the API key and domain, so it keeps working without a login.
- Store API keys hashed, and Meta/email credentials encrypted (`Crypt` or encrypted casts). Never log secrets or return them after creation.
- Rate-limit all public Ayla endpoints (`throttle`) and log auth failures.
- Feature gating is enforced on the server (Laravel), never only in the UI. If `email_assistant_enabled` is false, the email endpoints return `403` and the scheduler skips the account.

### 5.1 Subscription Expiry & Renewal

- Every account has `expires_at`, set at creation. The admin can edit it or extend it manually after payment. Payment and renewal are handled by the owner outside the plugin: the customer contacts us and pays, then the admin activates or extends the account.
- An account is **expired** when `expires_at` is in the past. Expiry is evaluated on the server on every request, in the same auth middleware as section 5. It must not rely only on a scheduled job.
- Expired accounts:
  - AI replies, new bookings, reminders, and email scanning stop. Laravel does not send messages to WhatsApp for the account.
  - Dashboard API endpoints return `403` with the error code `account_expired`. Admin-side data is kept, and nothing is deleted on expiry.
  - The WP plugin shows one neutral branded notice: "Your Ayla by Promera subscription has expired. Please contact Promera to renew." It shows no tymzh URL and no payment link. The message text comes from Laravel (`error.message`), not from plugin code.
  - The WhatsApp webhook still returns `200` to Meta, so Meta does not disable the webhook. Messages are acknowledged but not processed.
- Reactivation happens only when the admin extends `expires_at` (or toggles the account active) in `/admin/ayla/accounts/{id}`. It takes effect immediately, with no customer self-service.
- **Pre-expiry reminder to the owner (admin only):**
  - A daily scheduled command (`ayla:notify-expiring`) finds active accounts whose `expires_at` is 2 days away and notifies the owner through **three channels**, all from one `App\Notifications\Ayla\AccountExpiringNotification`:
    1. **Admin notification** on tymzh, using the existing system (`AdminNotificationController`, database notifications to admin users).
    2. **Email** to the owner's address (Laravel mail, an `App\Mail\Ayla` mailable).
    3. **WhatsApp** message to the owner's number, sent by Laravel directly to Meta using Promera's own sender number. This is a platform-level channel configured in `config/ayla.php` / `.env`. It is not any customer's WhatsApp config. Outside the 24h window it uses an approved template.
  - Each message includes the account name, domain, expiry date, and a link to the account's admin page (the link is for the owner only and is never sent to the customer).
  - Owner email, owner WhatsApp number, and the sender credentials come from `config/ayla.php` / `.env`, not hardcoded.
  - Channels are independent: a failure in one is logged and must not block the others. Each channel is queued and retried.
  - Send once per account per `expires_at` value per channel (store `expiry_notified_at` or an equivalent marker). It must be idempotent, and the marker resets when `expires_at` changes.
  - The admin area also shows an "Expiring soon" (within 2 days) and "Expired" badge/list on the Ayla accounts page.
  - The customer is **not** notified automatically. The owner contacts them to confirm renewal.
- The reminder lead time (2 days) lives in `config/ayla.php` (`expiry_notice_days`), not hardcoded.
- Time comparisons use UTC, and the expiry is treated as end of the day in the account's time zone.

### 5.2 Payments & Renewals Log

- Every payment or renewal is recorded in `account_payments`: `account_id`, `amount`, `currency`, `method`, `reference`, `paid_at`, `period_start`, `period_end`, `note`, `recorded_by`.
- Renewing is one admin action ("Record renewal") that creates the payment row and updates `expires_at` in a single transaction. New expiry = max(now, current expiry) + the paid period. Editing `expires_at` by hand without a payment row is allowed but must be audit-logged with a reason.
- Owner-only reports: revenue per month, per category, and per account, alongside the usage cost from 8.3 (so margin per account is visible).
- No card or bank data is stored. Payments are recorded manually by the owner. Online payment integration is out of scope until requested.
- Customers never see this data, and the plugin never receives it.

### 5.3 Admin Roles & Audit

- Roles for the Ayla admin area: `owner` (full access) and `support` (view accounts, view conversations, handle handoffs, view the doctors and business facts; **no** access to usage, cost, pricing, payments, instructions, credentials/API keys, or account deletion).
- Roles are stored in a new `admin_roles` table (user_id, role). The existing `users` table and `EnsureAdmin` are not modified. Existing admins without a role row are treated as `owner`. A new `EnsureAylaRole` middleware guards each route.
- Sensitive actions are written to an audit log (who, what, which account, when, IP): viewing a conversation, taking over a conversation, revealing or rotating a key, editing expiry or limits, publishing or rolling back an instruction, exporting or deleting patient data.

## 6. Multi-Tenancy & Data Isolation

- Every Ayla table that holds customer data has `account_id` (indexed, FK) and is queried through an account-scoped query or global scope.
- Never accept `account_id` from the request. Derive it from the authenticated API key and domain.
- Cross-tenant access is the worst possible bug. Every endpoint needs a test proving account A cannot read or write account B's data.

Core entities (adjust as needed): accounts, account_groups, account_settings (hours, language, clinic info), services (pricing catalogue), faqs, doctors, channels (WhatsApp config), contacts, conversations, messages, appointments, reminders, lead_scores, email_connections, email_messages, agent_instructions, instruction_versions, conversation_flags, categories, model_prices, usage_logs, account_payments, admin_roles, audit_logs, waitlist_entries.

- Each account belongs to exactly one **category** (`category_id`), chosen by the admin (see 8.1.1). Dental clinics and doctors are the primary use case, but nothing may be hardcoded to dentistry or to any category. Categories are data, not code.
- Promera's own use (e.g. on promera.ae, selling or using Ayla) is **just another account** (its own domain, its own instructions, its own category such as "Sales"). There is no special-case code path for it.

## 7. WhatsApp / Meta

- Meta calls the **customer's WP site** (`/wp-json/ayla/v1/webhook`), so tymzh.net is never exposed to the customer. The plugin forwards the **raw body and the original `X-Hub-Signature-256` header untouched** to `/api/ayla/v1/webhook/whatsapp`.
- Laravel verifies the Meta signature and handles the verification handshake (`hub.challenge`), de-duplicates by message ID, and handles retries safely.
- The WP relay returns `200` to Meta quickly. Laravel queues the processing.
- Respect WhatsApp's 24-hour window: outside it, use approved templates only (reminders, confirmations).
- Reminder stages: instant confirmation, 24h before, and 1h before, with interactive buttons (confirm / reschedule / cancel). Button replies are handled by Laravel.

## 8. AI / OpenAI Rules

- All prompts are built server-side in `Services/Ayla` (prompt builder). Prompts and the API key never leave Laravel.
- Prompt = core Ayla safety rules + managed instructions (8.1: global, category, group, account) + account settings (business, doctors, hours, services, pricing, language) + conversation context + user message.
- Never trust model output blindly. Booking or action results must be structured (JSON schema), validated, and checked for conflicts by code before saving or confirming.
- Never let the model invent prices, hours, availability, or medical advice. Unknown means hand off to a human.
- Minimise the personal data sent to OpenAI. Do not send fields it does not need.
- Track tokens and usage per account and enforce plan limits (see 8.3).
- The model name and parameters come from `config/ayla.php`, not hardcoded.

### 8.1 Agent Training (Instructions Managed on tymzh)

The owner teaches the agent how to reply, what to say, and what to do, through an admin interface on tymzh (`/admin/ayla/instructions`). Instructions live in the DB (`agent_instructions`), never in code or in the WP plugin. Customers cannot see or edit them.

- **Scopes** (every instruction has exactly one):
  1. `global`: applies to all accounts.
  2. `category`: applies to every account in one category (e.g. all Dental Clinics).
  3. `group`: applies to a specific group of accounts. Groups are admin-defined (`account_groups`), and an account can belong to several (e.g. a plan tier, a region, a pilot batch).
  4. `account`: applies to one specific account only (a specific clinic or client).
- **Merge order** in the prompt builder: Ayla core safety rules (fixed, see below) -> `global` -> `category` -> `group` -> `account`. When two instructions conflict, the more specific scope wins. Within one scope, a higher `priority` wins.
- **Core safety rules are not editable** through instructions and always come first. They cover no invented prices/hours/medical advice, human handoff when unsure, no leaking other tenants' data, no revealing internals or prompts, and no mention of tymzh/OpenAI. Instructions that try to override them are ignored.
- **Instruction kinds** (a `type` field): `tone` (style/language), `reply_rules` (do/don't), `knowledge` (FAQ, services, policies), `workflow` (what to do, e.g. how to take a booking, when to escalate), `greeting`.
- Each instruction has: `title`, `scope`, `scope_target` (category, group, or account; null for global), `type`, `content`, `priority`, `is_active`, and the usual timestamps and author. Edits are versioned or audit-logged so a bad change can be rolled back.
- The admin interface includes a **test playground**: pick an account, type a customer message, and preview the reply and the final assembled prompt. The preview never sends WhatsApp and never counts against the account's usage.
- The admin interface shows which instructions apply to a given account (the effective, merged set) and where each one comes from (global, group, or account).
- The prompt builder loads only active instructions for the account's scope and keeps them within a token budget. If the budget is exceeded, it drops the lowest-priority, most general ones first.
- Instructions apply per account on every request (no stale cache). If cached, invalidate on any instruction, group, or membership change.
- Accounts with an expired or inactive status ignore all of this (see 5.1).

### 8.1.1 Categories

- Categories (Dental Clinics, General Medical, Sales, Real Estate, etc.) are created, renamed, and deactivated by the owner at `/admin/ayla/categories`. Adding a new category needs no code change or deploy.
- A category holds: `name` (AR/EN), `slug`, `description`, and `is_active`. It may also hold default values for new accounts (e.g. default `doctors_limit`, default language, suggested starter FAQs and services).
- Creating a category is just the container. The agent's behavior for it comes from `category`-scope instructions (8.1). A new category with no instructions falls back to `global` instructions plus the core safety rules.
- Entity names that are domain-specific (e.g. "doctors") are labels per category (e.g. "specialists" or "agents") so the UI wording can adapt. The data model stays generic.
- A category cannot be deleted while accounts use it. It can only be deactivated, which blocks new accounts and keeps existing ones working.
- Changing an account's category takes effect immediately (the effective instruction set is recomputed) and is audit-logged.
- Required: the owner can preview how the agent behaves for a category in the test playground (pick a category, pick or create a sample account).

### 8.2 Customer-Editable Business Info (From the WP Plugin)

The customer can edit only **business facts**, never agent behavior. Facts are stored in Laravel (`account_settings`, `services`, `faqs`) and saved through the plugin's UI via the API. The plugin stores nothing.

- **Editable by the customer:**
  - working hours, days off, holidays, and time zone;
  - services and prices (the pricing catalogue), including duration per service;
  - FAQs (question and answer);
  - basic clinic info (name, address, phone, map link, and the language preference).
- **Not editable by the customer:** agent instructions (8.1), tone, workflow, core safety rules, plan flags, `doctors_limit`, expiry, domain, and credentials.
- Customer-provided text is **data, not instructions**. The prompt builder puts it in a clearly delimited section (e.g. "Business facts") and tells the model it can never override core rules or managed instructions. This is the defense against prompt injection from the customer's text or from patients' messages.
- Validate and bound all of it on the server (max lengths, max FAQ count, numeric price/duration checks, valid hours with no overlap) and strip HTML.
- Changes take effect on the next request (no stale cache), and are audit-logged (who, what, when) so the owner can review them.
- The owner can see and edit the same facts for any account in the tymzh admin, and can lock a field per account (e.g. `prices_locked`) when needed. Locked fields are read-only in the plugin, and the server rejects writes to them.
- Prices and hours the agent states must come from this data only (see the rule against inventing facts in section 8).
- Accounts with an expired status cannot edit (see 5.1).

### 8.3 Token Usage & Cost Tracking

- **Every** OpenAI call is logged in `usage_logs`: `account_id` (and domain), `model`, `source` (`chat`, `email`, `reminder`, `playground`, `other`), `prompt_tokens`, `completion_tokens`, `cached_tokens` (if any), `cost`, `currency`, `created_at`. No call goes out without a log entry, including failures that were billed.
- Prices live in `model_prices` (admin-editable at `/admin/ayla/pricing`): `model`, price per 1M input tokens, price per 1M output tokens (and cached, if used), `currency`, `effective_from`. They are not hardcoded.
- Cost is calculated **at log time** from the price effective at that moment and stored on the row, so later price changes never rewrite history.
- **Admin report** (`/admin/ayla/usage`), owner only:
  - per domain/account: total tokens (input/output), total cost, number of requests, average cost per conversation;
  - filters by date range, category, group, model, and `source`; sort by cost; totals row;
  - drill-down from an account to daily usage;
  - CSV export.
- `playground` usage is recorded (so cost is visible) but does not count against an account's plan limits.
- Plan token limits (if set on the account) are enforced on the server. At the limit, the agent stops replying and hands off per the plan policy. The owner is notified through the admin notifications system.
- **Daily cost cap per account** (`daily_cost_cap`, default from `config/ayla.php`, editable per account). At 80% the owner is notified. At 100% the agent stops calling OpenAI for that account, conversations go to human handoff (8.5), and the owner is alerted. This protects against loops, spam, and abuse.
- **Per-contact rate limiting:** a cap of messages per minute and per day per phone number. Beyond it, messages are stored but not answered. Messages from the account's own number, status callbacks, and echo/bot loops never trigger a reply.
- **Spike detection:** if an account's hourly cost or message volume is far above its recent average, the owner is alerted.
- **Cost and price data are never exposed** to customers, the plugin, or any `/api/ayla/v1` response. The customer dashboard may show activity counts only (messages, conversations), not tokens or cost.

### 8.4 Language: Reply in the Patient's Language

- The agent replies in **the language of the patient's latest message, whatever it is** (Arabic and its dialects, English, Urdu, Hindi, Tagalog, Russian, French, and any other). It is detected per message. If the patient switches language, the agent switches too. If the message is ambiguous (e.g. only a number or an emoji), it keeps the language of the previous message, then falls back to the account's default language.
- Business facts, FAQs, and instructions can be written in any language. The agent translates faithfully and must never change prices, numbers, dates, times, doctor names, or addresses while translating.
- The detected language is saved on the contact (`language`) and used to choose reminder and follow-up templates.
- Meta templates exist per language. If no approved template exists in the patient's language, fall back to the account's default language, then English.
- Core safety rules, emergency handling (8.5), and opt-out detection (12.1) must work in every language, not only Arabic and English.
- Tests must cover at least Arabic, English, one more RTL language, and one non-Latin non-RTL language.

### 8.5 Human Handoff & Emergencies

- Every conversation has a status: `ai` (the agent replies), `human` (the agent is silent), or `closed`. Messages are always stored regardless of the status.
- **Handoff triggers:** the patient asks for a human, the agent is unsure or lacks data, a complaint or angry tone, repeated failed attempts, an emergency (below), the cost cap or an outage (8.3, 12.2), or the owner or staff taking over.
- When the status becomes `human`, the agent stops replying to that conversation. A single neutral message tells the patient that a team member will reply (in the patient's language).
- **Staff alert:** Laravel sends a WhatsApp alert to the clinic's `staff_alert_number` (editable by the customer in the plugin settings) with the reason, a short summary, and the patient's number. Staff then contact the patient directly. The alert contains the minimum data needed.
- The owner and `support` can reply from the tymzh admin conversation view (Laravel sends through Meta). Every takeover is audit-logged.
- The plugin shows a "needs a human" badge. The **only write action** allowed in the dashboard is "Mark as handled / Resume Ayla", done through the API. Laravel decides whether to accept it.
- Optional auto-resume after N hours of silence, configured in `config/ayla.php`, off by default.
- **Emergencies:** detected by code (multilingual keyword and pattern rules) **plus** a model-reported flag. The code path does not depend on the model alone, and it errs on the side of escalating. Examples: severe pain, bleeding, facial swelling, trouble breathing or swallowing, accident or trauma.
  - The reply is a **fixed template** (not free AI text) in the patient's language: it does not diagnose or give medical advice, and it gives the clinic's emergency contact (`emergency_contact`) and tells them to call local emergency services if serious.
  - The conversation moves to `human` and the staff are alerted immediately.
  - Each account must have `emergency_contact` set before the agent can be enabled. A global default exists in `config/ayla.php`.

### 8.6 Learning from Corrections & Draft/Publish

- In the admin conversation view, the owner can **flag an agent reply** (wrong, rude, inaccurate, off-policy), write the expected reply, and click "Create instruction from this". It opens a prefilled instruction where the owner chooses the scope (global, category, group, or account). Flags are stored in `conversation_flags`.
- Instructions have a `status`: `draft` or `published`. Drafts are only used in the test playground and for accounts explicitly listed as test accounts. Publishing applies the instruction at its scope.
- Every publish creates a row in `instruction_versions`. The owner can roll back to any previous version in one click. Both actions are audit-logged.
- The playground can replay a past conversation against a draft so the owner can compare the old reply and the new one before publishing.
- Viewing real patient conversations is restricted to `owner` and `support`, and each view is audit-logged.

## 9. Booking Engine

- Availability and conflict checks are done in code (business hours, duration, buffers, existing appointments, time zone per account), never by the AI.
- Store times in UTC and convert using the account's time zone.
- Appointment changes (create, reschedule, cancel) are transactional and trigger reminder rescheduling.

### 9.1 Doctors & Doctor Limit

- The customer manages their doctors from a **screen in the WP plugin** (add, edit, remove: name, specialty, working days/hours, appointment duration, services). The plugin is UI only. All of it is saved to and read from Laravel (`doctors` table, scoped by `account_id`).
- The base package allows **up to 3 doctors**. The limit is a per-account field (`doctors_limit`, default from `config/ayla.php`, `default_doctors_limit = 3`). The admin can raise it later in `/admin/ayla/accounts/{id}` (e.g. as a paid upgrade).
- The limit is enforced **on the server**: adding a doctor beyond `doctors_limit` returns `403` with the error code `doctors_limit_reached` and a neutral branded message. The plugin only displays that message, and may show the "x of y" count from the API.
- Lowering `doctors_limit` below the current count never deletes data. Existing doctors are kept, and only new additions are blocked.
- Removing a doctor is a soft delete (`is_active=false` / `deleted_at`) so past appointments and history stay intact. Doctors with future appointments cannot be removed until those are cancelled or reassigned.
- Bookings, availability, conflict checks, and reminders are **per doctor**. The agent offers or asks for a doctor, and only available, active doctors are offered.
- The owner sees all doctors of every account in the tymzh admin (per account page: list, count versus limit, status).
- Doctor data is shown in the dashboard and used by the prompt builder from the DB only. The plugin never stores it.

### 9.2 Follow-ups, No-shows & Waitlist

- **Post-visit follow-up:** a thank-you message and an optional review request after a completed appointment (approved template, patient's language).
- **No-show follow-up:** after a missed appointment, one polite message offering to rebook.
- **Waitlist:** when an appointment is cancelled, the freed slot is offered to matching waitlisted patients (`waitlist_entries`). The first confirmation wins, and the slot is claimed atomically so it is never double-booked.
- Gated by flags per account (`followups_enabled`, `waitlist_enabled`) and plan.
- All business-initiated messages respect consent and opt-out (12.1), quiet hours in the account's time zone (no night messages), and a frequency cap per patient.

### 9.3 Google Calendar Sync (Optional)

- Optional per doctor, gated by a plan flag (`calendar_sync_enabled`). Laravel stays the source of truth for appointments.
- Read: external busy times block availability. Write: confirmed appointments are created, moved, or cancelled in the doctor's calendar.
- A sync failure never blocks or rolls back a booking. It is retried and flagged.
- OAuth tokens are encrypted and stored in Laravel only.
- The OAuth redirect URI must not expose tymzh.net to the customer. It points to the customer's WP site (`/wp-json/ayla/v1/oauth/{provider}/callback`), and the plugin only forwards `code` and `state` to Laravel. The owner can also connect it from the tymzh admin on the customer's behalf.

## 10. Email Assistant (Optional Add-on)

- Available only when `email_assistant_enabled` is true for the account.
- Scans the client's inbox, classifies important inquiries, and sends an instant WhatsApp alert for high-priority emails.
- Read-only by default. It never sends or deletes emails without an explicit future feature and approval.
- Email credentials/tokens are encrypted and stored in Laravel only.
- If the email connection uses OAuth, the redirect follows the same rule as 9.3: it goes through the customer's WP site and never exposes tymzh.net.

## 11. WordPress Plugin Conventions

- Location: `promera/ayla/wp-plugin/ayla-by-promera/`.
- Plugin name shown to users: **Ayla by Promera**. Text domain: `ayla-by-promera`. Function/class/option prefix: `ayla_` / `Ayla_`. All strings are translatable (Arabic and English, RTL supported).
- **Settings page** (Settings -> Ayla by Promera): fields `domain`, `username`, `password`, `api_key`. The password and key fields are masked and never echoed back into the HTML.
- **All calls to Laravel are server-side (PHP) via `wp_remote_request`.** The browser never talks to tymzh.net and never receives the API key. UI JS calls the plugin's own WP endpoints (admin-ajax or WP REST) which proxy to Laravel.
- The Laravel base URL is a single constant in one file. It is never printed in HTML, JS, errors, or settings.
- Error messages shown to users are generic and branded ("Ayla could not complete the request"). Details go to Laravel logs only, never exposing internal URLs.
- UI is views only. The plugin dashboard is **read-only** and renders JSON computed by Laravel:
  - overview and comprehensive statistics: conversations, messages, bookings by status and by doctor, conversion rate, no-shows, reminder confirmations, response times, busiest hours, email alerts (if the add-on is on);
  - **prospective customers (leads)**: list with lead score, source, last message, and status;
  - appointments, conversation history, and reminders.
  All numbers, aggregates, and charts' data are computed on tymzh. The plugin only formats and displays them. It never computes business metrics from its own data, since it has none.
- Settings-style screens (doctors, hours, services and prices, FAQs, clinic info) are the only places the customer can write, and only through the API (see 8.2 and 9.1).
- The plugin has no screen for editing AI instructions. That is owner-only, on tymzh.
- The plugin never shows tokens, cost, prices of the model, categories, or other accounts' information.
- Security: nonces on every action, `current_user_can('manage_options')` on admin screens and proxy endpoints, sanitise input, escape output, no `eval`, no remote code, no CDN assets (bundle them).
- Webhook relay endpoint (`/wp-json/ayla/v1/webhook`): handles the GET handshake and POST relay only. It does not parse, alter, or store the payload.
- The plugin must degrade safely when tymzh.net is unreachable (show a neutral status, no fatal errors, and still return `200`/`5xx` to Meta as appropriate).
- **Versioning:** every request carries the plugin version (`X-Ayla-Plugin-Version`). Laravel keeps `min_plugin_version` in `config/ayla.php` and rejects older versions with `426` and the code `plugin_update_required` and a branded message. The API is versioned (`/api/ayla/v1`). A breaking change goes to `/v2`, and `v1` stays alive for every still-supported plugin version.
- **Updates:** the plugin updates through WordPress's standard update mechanism. The update source must be a Promera-branded URL that never reveals tymzh.net (the exact domain is to be decided by the owner).

## 12. Security & Privacy (Dental/Medical Context)

- Treat patient and contact data as sensitive: collect the minimum, encrypt secrets, and never log message bodies, phone numbers, or tokens in plain logs.
- Validate all input with Form Requests. Use Eloquent or query-builder bindings only (no raw string-concatenated SQL).
- Follow the OWASP Top 10. No debug output (`dd`, `dump`, `var_dump`) in committed code.
- Provide a way to delete or export an account's data (planned: `admin/ayla/accounts/{id}`).
- Secrets live in `.env` / encrypted DB columns, never in the repo or the plugin source.

### 12.1 Consent, Opt-out, Retention & Erasure

- **Patient-initiated replies:** a patient who messages first is answered in that conversation.
- **Business-initiated messages** (reminders, follow-ups, waitlist offers) need recorded consent (`consent_at`, `consent_source`), captured when the patient books or agrees. Without consent, they are not sent.
- **Opt-out:** the code (not the model) detects opt-out words in every language (e.g. STOP, UNSUBSCRIBE, إلغاء, توقف). It marks the contact `opted_out`, sends one confirmation, and stops all business-initiated messages. The patient can opt back in by messaging again.
- An optional first-contact privacy notice (configurable per account and language) tells the patient they are talking to an AI assistant and how their data is used.
- **Retention:** each account has `retention_days` (default in `config/ayla.php`, editable by the owner). A scheduled, idempotent job purges or anonymizes older messages and contacts. Appointments are kept with minimal data.
- **Erasure and export:** an admin action finds one patient by phone number and deletes or exports their data across all Ayla tables. It is audit-logged and owner-only.
- OpenAI is used through the API only, with the provider's no-training terms. Zero data retention is enabled where available.
- **Legal check before onboarding clinics (owner action):** health data may be subject to UAE and emirate-level rules (e.g. data residency and cross-border transfer limits, PDPL, and the health regulators' requirements). Verify hosting location and OpenAI use with legal advice. Do not onboard real patients before that is settled.

### 12.2 Reliability & Monitoring

- **Per-account health page** in the admin: WhatsApp connection status, Meta token validity and expiry, last inbound and outbound message, last error, failed-job count, and queue lag.
- A daily job checks every account's Meta token and notifies the owner 7 days before it expires or as soon as it becomes invalid.
- **Owner alerts** (admin notification, plus email and WhatsApp as in 5.1) for: send failures above a threshold, an invalid or expiring token, queue backlog, scheduler not running, a spike of OpenAI errors, and the cost cap being reached.
- **Scheduler heartbeat:** `ayla:heartbeat` runs every minute and an alert fires if it goes missing.
- **Idempotency:** inbound by Meta message ID. Outbound sends are retried with backoff and de-duplicated. A reminder is never sent twice. Final failures are stored with a status and flagged.
- **OpenAI outage:** after N failures the agent sends one neutral message, the conversation moves to `human` (8.5), and the owner is alerted. A patient is never left silently unanswered.
- **Logs** are structured and contain no personal data (see 12). **Database backups** run daily, and restore is tested regularly.

## 13. Workflow Rules for AI Assistants

1. Read this file and the relevant existing code before editing.
2. Make small, additive, reviewable changes. One concern per change.
3. Do not create extra files, docs, or abstractions that were not requested.
4. Do not run destructive commands (`migrate:fresh`, `migrate:rollback`, dropping tables, deleting files) or commit/push without explicit approval.
5. Add or update tests for tenant isolation, auth and domain verification, feature gating, expiry (expired account blocked, reminder sent once at 2 days), multilingual replies, emergency detection, handoff silence, opt-out, and cost-cap behavior for each new endpoint.
6. Match the existing code style. Comments only for what the code cannot show.
7. If a requirement is ambiguous or touches an existing feature, **ask the owner first**.

## 14. Build Order

1. Migrations and models (`ayla_*`) with tenant scoping.
2. Admin area: create or edit accounts (domain, credentials, API key, plan flags, expiry, `doctors_limit`, category, groups) and manage categories.
3. API auth middleware (API key + domain verification, throttling).
3b. Expiry enforcement in the auth middleware, plus the `ayla:notify-expiring` scheduled command (admin, email, and WhatsApp notifications).
3c. Doctors API (with limit enforcement) and the agent-instructions admin area (global / category / group / account scopes, test playground).
3d. Business-facts API (hours, services and prices, FAQs, clinic info) with field locking and audit log.
4. WhatsApp webhook endpoint, prompt builder, OpenAI orchestration (with `usage_logs` and cost calculation on every call), and outbound sending.
5. Booking engine and reminders (scheduler + queue).
6. Dashboard read endpoints (appointments, conversations, leads, comprehensive statistics), computed on tymzh.
6b. Admin usage and cost report per domain (`/admin/ayla/usage`) and model pricing management.
7. WP plugin: settings page, webhook relay, proxy layer, dashboard views.
8. Email assistant add-on (gated by feature flag).

### Release Phases

**Before launch (required):** steps 1–8 above, plus:
- 8.4 reply in the patient's language;
- 8.5 human handoff and emergencies;
- 8.3 daily cost cap, rate limiting, and spike alerts;
- 12.1 consent, opt-out, retention, and the legal check;
- 12.2 reliability, health page, token checks, and heartbeat;
- 5.3 audit log for sensitive actions.

**After launch (planned):**
- 8.6 learning from corrections and draft/publish;
- 9.2 follow-ups, no-shows, and waitlist;
- 9.3 Google Calendar sync;
- 5.2 payments and renewals log;
- 5.3 `support` role;
- plugin versioning and the update channel (section 11).
