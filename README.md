# 360 AI

360 AI is the general AI layer for the 360 platform. It is **not** an SEO-only plugin. SEO and GEO/AEO will be modules alongside future content, auditing, internal-linking, image, Search Console, and analytics-assisted workflows.

## Current development version: 0.3.0-dev

The foundation release provides:

- a capability-protected **WordPress Admin → 360 AI** diagnostics dashboard;
- graceful dependency handling when 360 Platform Core is unavailable;
- normalized SiteContext, Clinic, Doctor, relationship, and location summaries through Platform Core;
- lightweight Page/Post counts and a bounded reusable content inventory;
- a structured context builder for future modules.

Version 0.2.0 adds **Content Opportunities**, a human-review workflow that builds bounded, Platform Core-grounded site context and returns exactly five structured recommendations. It does not create or publish Pages, Posts, drafts, images, SEO metadata, or Clinic/Doctor source data.

## Content Opportunities

- A provider-neutral contract separates context collection, provider transport, deterministic validation, private storage, and presentation.
- `MockProvider` supplies deterministic fixtures only when `GLOBAL360_AI_USE_MOCK_PROVIDER` is explicitly enabled locally.
- `OpenAIProvider` uses the Responses API with strict JSON Schema structured output and `store: false`.
- `AnthropicProvider` uses the server-side Messages API with JSON Schema structured output and the pinned `claude-sonnet-5` model, selected for its balance of reasoning, latency, and cost.
- OpenAI and Anthropic credentials are stored independently. `GLOBAL360_AI_OPENAI_API_KEY` and `GLOBAL360_AI_ANTHROPIC_API_KEY` override their respective protected database options.
- Existing installations continue to select OpenAI by default; administrators may explicitly select Anthropic without losing either saved credential. The Mock provider remains local/test-only.
- Blank key submissions preserve the saved key; removal requires an explicit checkbox. Keys are never rendered, logged, or stored in analysis history.
- Validation requires exactly five opportunities, controlled types/categories, existing entity/link IDs, duplicate checks, and Core-grounded local recommendations.
- Private option-based history retains ten records with provider/model, context hash, status, token usage, bounded safe diagnostics, errors, and validated recommendations—never credentials, request payloads, raw provider responses, or the full site-analysis context.
- Recommendations are medical/YMYL topic proposals for later human review. They may not invent facts, studies, efficacy, diagnoses, providers, clinics, or geographic presence.

Draft generation is deliberately not part of 0.2.0. No public endpoint, Clinic/Doctor write, or SEOPress write exists.

## Draft-generation development

The `0.3.0-dev` line adds an explicitly human-controlled workflow: every stored opportunity begins pending, an administrator may approve or reject it, and only an approved opportunity can invoke draft generation. Generated content is always inserted as a WordPress draft for editorial review and is never published automatically.

- Workflow state is stored separately from immutable analysis history and supports only validated transitions between pending, approved, rejected, and draft-created states.
- OpenAI and Anthropic share one structured draft contract; Anthropic receives its established provider-safe schema transformation, while `MockProvider` supports deterministic local testing without paid requests.
- Deterministic validation rejects unexpected fields or post types, unsafe HTML, active content, shortcodes, Markdown wrappers, invalid internal-link IDs, invalid title/slug correspondence, and content outside bounded lengths.
- A request-local generation context includes only the approved opportunity, relevant site context, explicitly referenced content links, and referenced Core entities/locations.
- Duplicate lookup and an atomic short-lived generation lock prevent double-click or concurrent duplicate drafts and provider calls.
- Draft provenance, token usage, SEO title/description suggestions, and validated internal-link suggestions use private `_global360_ai_*` metadata. This milestone deliberately does not write undocumented SEOPress fields or insert AI-created URLs.
- Clinic, Doctor, relationship, and location records remain read-only inputs owned by Platform Core/API Sync. Generation writes only a new Page/Post draft and 360 AI metadata on that draft.
- Provider and validation failures leave the opportunity approved, create no post, perform no automatic retry, and retain only bounded sanitized diagnostics.

## Architecture

- `CoreAdapter` is the only 360 domain-data integration boundary and never reimplements Core legacy-meta interpretation.
- `SiteContextBuilder` creates provider-neutral structured context without presentation HTML.
- `ContentInventory` uses ordinary WordPress APIs for Pages and Posts and does not load post bodies.
- `AdminPage` owns capability checks, escaping, and dashboard presentation.

360 Platform Core must be active for 360 domain context. Pages and Posts remain available through standard WordPress APIs when Core is unavailable.

## Development

- PHP 7.4 or newer
- WordPress 6.0 or newer
- 360 Platform Core for connected integration tests

Run focused tests with:

```bash
php tests/run.php
php tests/analysis.php
php tests/anthropic.php
php tests/draft-generation.php
```

Run `tests/local-integration.php`, `tests/local-analysis.php`, and `tests/local-draft-generation.php` only against a local development WordPress installation. The draft-generation harness creates and removes a disposable draft and restores its test workflow/history state.
