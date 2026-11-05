# 360 AI

360 AI is the general AI layer for the 360 platform. It is **not** an SEO-only plugin. SEO and GEO/AEO will be modules alongside future content, auditing, internal-linking, image, Search Console, and analytics-assisted workflows.

## Current release: 0.4.4

The foundation release provides:

- a capability-protected **WordPress Admin → 360 AI** diagnostics dashboard;
- graceful dependency handling when 360 Platform Core is unavailable;
- normalized SiteContext, Clinic, Doctor, relationship, and location summaries through Platform Core;
- lightweight Page/Post counts and a bounded reusable content inventory;
- a structured context builder for future modules.

**Content Opportunities** is a human-review workflow that builds bounded, Platform Core-grounded site context. Providers return eight ordered candidates; deterministic validation keeps the first five valid recommendations. It does not create or publish normal Pages or Posts, images, or Clinic/Doctor source data.

Version 0.4.2 makes established-site analysis more robust without weakening its safeguards: providers return eight ordered candidates, candidate-level duplicate, cannibalization, entity, and geographic failures are rejected independently, and exactly the first five valid opportunities are retained. Fewer than five valid candidates still fails closed, makes no retry, and saves no recommendations.

Version 0.4.3 adds bounded private diagnostics for Anthropic transport failures and correctly distinguishes transport, provider HTTP, deterministic validation, and historical failures in analysis history. It does not change provider requests, validation, generation, or publishing behavior.

Version 0.4.4 adds the standard 360 manifest-based GitHub updater. Production installations read `plugin-manifest.json` from repository `main`, compare semantic versions, show normal WordPress Admin updates, download the `main` branch package, and normalize GitHub's extracted directory to `wp-content/plugins/360-ai/`. The updater claims only packages containing the 360 AI bootstrap and never replaces an existing unrelated target.

## Content Opportunities

- A provider-neutral contract separates context collection, provider transport, deterministic validation, private storage, and presentation.
- `MockProvider` supplies deterministic fixtures only when `GLOBAL360_AI_USE_MOCK_PROVIDER` is explicitly enabled locally.
- `OpenAIProvider` uses the Responses API with strict JSON Schema structured output and `store: false`.
- `AnthropicProvider` uses the server-side Messages API with JSON Schema structured output and the pinned `claude-sonnet-5` model, selected for its balance of reasoning, latency, and cost.
- OpenAI and Anthropic credentials are stored independently. `GLOBAL360_AI_OPENAI_API_KEY` and `GLOBAL360_AI_ANTHROPIC_API_KEY` override their respective protected database options.
- Existing installations continue to select OpenAI by default; administrators may explicitly select Anthropic without losing either saved credential. The Mock provider remains local/test-only.
- Blank key submissions preserve the saved key; removal requires an explicit checkbox. Keys are never rendered, logged, or stored in analysis history.
- Validation requires exactly eight candidates, selects exactly five valid opportunities, enforces controlled types/categories and existing entity/link IDs, rejects duplicates/overlaps per candidate, and requires Core-grounded local recommendations. Fewer than five valid candidates fails closed without saving recommendations.
- Private option-based history retains ten records with provider/model, context hash, status, token usage, bounded safe diagnostics, errors, and validated recommendations—never credentials, request payloads, raw provider responses, or the full site-analysis context.
- Recommendations are medical/YMYL topic proposals for later human review. They may not invent facts, studies, efficacy, diagnoses, providers, clinics, or geographic presence.

Draft generation is deliberately not part of 0.2.0. No public endpoint, Clinic/Doctor write, or SEOPress write exists.

## Draft generation

Version 0.3.0 added an explicitly human-controlled workflow: every stored opportunity begins pending, an administrator may approve or reject it, and only an approved opportunity can invoke draft generation.

Version 0.4.1 separates the AI-assisted editorial pipeline into two purpose-built workspaces backed by one permanent `g360_content` record: **Content Drafts** for generated work under review, and **Content** for explicitly promoted items ready for the normal draft/pending/published lifecycle. Neither workspace uses normal Pages or Posts.

- Workflow state is stored separately from immutable analysis history and supports only validated transitions between pending, approved, rejected, and draft-created states.
- Content Opportunities is an actionable queue with Active, Published, Rejected, Superseded, and All views. The newest successful five-opportunity analysis is current; untouched pending recommendations from older successful analyses are derived as Superseded without mutating or deleting history. Published Content, explicit rejection, and meaningful in-progress work take precedence over supersession. Published is derived from the associated `g360_content` post's current WordPress status; unpublishing or trashing Content with established workflow provenance returns it to Active.
- OpenAI and Anthropic share one structured draft contract; Anthropic receives its established provider-safe schema transformation, while `MockProvider` supports deterministic local testing without paid requests.
- Deterministic validation accepts only attribute-free `p`, `h2`, `h3`, `ul`, `ol`, `li`, `strong`, and `em` markup. Links remain suggestions by validated post ID rather than provider-authored `<a>` elements. All other tags and all attributes—including classes, styles, IDs, event handlers, and provider URLs—remain fail-closed and reject the draft instead of being silently stripped.
- A request-local generation context includes only the approved opportunity, relevant site context, explicitly referenced content links, and referenced Core entities/locations.
- Duplicate lookup and an atomic generation lock prevent double-click or concurrent duplicate drafts and provider calls.
- Provenance, token usage, SEO suggestions, and validated internal-link suggestions remain in private `_global360_ai_*` metadata on Content.
- Content Drafts keep the intended slug and generated SEO suggestions in private editorial fields. They have no public route, public REST record, canonical, or sitemap entry.
- A nonce- and capability-protected **Move to Content** action promotes the same record idempotently. It creates no copy, publishes nothing, and makes no provider request.
- Generation retains one primary and two-to-four secondary target keyphrases as private editorial provenance. Promotion initializes SEOPress's canonical title, description, Target Keywords, Open Graph, and X title/description fields from the reviewed draft values. The normal SEOPress editor is the editable SEO source of truth in Content, and later human edits are never overwritten by repeated promotion.
- Published Content uses a clean root-level URL with no CPT base. Existing public Page, Post, and CPT routes are reserved, collision promotion/publication fails closed, and normal Page/Post routing is unchanged.
- Only promoted, published Content is public and included in SEOPress XML sitemap handling.
- Published Content is also included in the same bounded existing-content context used by future analysis and deterministic overlap checks. Draft, pending, private, and trashed Content is excluded so unfinished work does not suppress valid recommendations.
- Content uses a dedicated non-public `g360_content_tag` taxonomy, separate from normal Post Tags. New drafts receive three to six validated provider-suggested Content Tags, editors control the live term assignments, and the original suggestions remain private provenance. Only bounded tags attached to published Content enter future analysis context; Content Tag archives, rewrites, and taxonomy sitemaps remain disabled.
- Content Tags remain private editorial and analysis metadata and are not rendered on public Content pages. The scoped Content stylesheet presents the active theme's existing Related Articles list in three desktop columns, two tablet columns, and one mobile column without changing its selection logic.
- The opportunity's Page/Post suggestion remains provenance only. Promotion and publication never convert or copy Content into a normal Page or Post.
- Clinic, Doctor, relationship, and location records remain read-only inputs owned by Platform Core/API Sync. Generation writes only to the dedicated Content type.
- Provider and validation failures leave the opportunity approved, create no post, perform no automatic retry, and retain only bounded sanitized diagnostics. A private, 20-entry-per-opportunity attempt history records provider/model, token usage, status, and structural validation names such as rejected tags or attributes, never prompts, context, generated prose, credentials, or raw responses.

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

Run `tests/local-integration.php`, `tests/local-analysis.php`, `tests/local-opportunity-lifecycle.php`, and `tests/local-draft-generation.php` only against a local development WordPress installation. The lifecycle and draft-generation harnesses create and remove disposable Content records and restore their test workflow/history state.
