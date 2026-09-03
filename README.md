# 360 AI

360 AI is the general AI layer for the 360 platform. It is **not** an SEO-only plugin. SEO and GEO/AEO will be modules alongside future content, auditing, internal-linking, image, Search Console, and analytics-assisted workflows.

## Current development version: 0.2.1-dev

The foundation release provides:

- a capability-protected **WordPress Admin → 360 AI** diagnostics dashboard;
- graceful dependency handling when 360 Platform Core is unavailable;
- normalized SiteContext, Clinic, Doctor, relationship, and location summaries through Platform Core;
- lightweight Page/Post counts and a bounded reusable content inventory;
- a structured context builder for future modules.

The reviewed 0.1.0 foundation is published on `main`. The current development branch adds **Content Opportunities**, a human-review workflow that builds bounded site context and returns exactly five structured recommendations. It does not create Pages, Posts, drafts, images, SEO metadata, or domain data.

## Content Opportunities development

- A provider-neutral contract separates context collection, provider transport, deterministic validation, private storage, and presentation.
- `MockProvider` supplies deterministic fixtures only when `GLOBAL360_AI_USE_MOCK_PROVIDER` is explicitly enabled locally.
- `OpenAIProvider` uses the Responses API with strict JSON Schema structured output and `store: false`.
- `AnthropicProvider` uses the server-side Messages API with JSON Schema structured output and the pinned `claude-sonnet-5` model, selected for its balance of reasoning, latency, and cost.
- OpenAI and Anthropic credentials are stored independently. `GLOBAL360_AI_OPENAI_API_KEY` and `GLOBAL360_AI_ANTHROPIC_API_KEY` override their respective protected database options.
- Existing installations continue to select OpenAI by default; administrators may explicitly select Anthropic without losing either saved credential. The Mock provider remains local/test-only.
- Blank key submissions preserve the saved key; removal requires an explicit checkbox. Keys are never rendered, logged, or stored in analysis history.
- Validation requires exactly five opportunities, controlled types/categories, existing entity/link IDs, duplicate checks, and Core-grounded local recommendations.
- Private option-based history retains ten records with provider/model, context hash, status, token usage, errors, and validated recommendations—never the key or full site-analysis context.
- Recommendations are medical/YMYL topic proposals for later human review. They may not invent facts, studies, efficacy, diagnoses, providers, clinics, or geographic presence.

Draft generation is deliberately not part of 0.2.0. No public endpoint, Clinic/Doctor write, or SEOPress write exists.

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
```

Run `tests/local-integration.php` only against a local development WordPress installation.
