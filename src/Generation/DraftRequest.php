<?php

namespace Global360\AI\Generation;

final class DraftRequest {
	public static function instructions(): string {
		return 'Write one complete educational WordPress draft for the approved opportunity and supplied context. Return only the required structured fields. The content field must contain attribute-free HTML using only <p>, <h2>, <h3>, <ul>, <ol>, <li>, <strong>, and <em>. Do not use any other HTML tags or attributes, including <a>, href, inline styles, classes, IDs, data-* attributes, event handlers, H1, blockquote, div, span, table, br, hr, b, or i. Do not include Markdown, fenced code blocks, links, or URLs in content. Do not insert hyperlinks or <a> tags into the generated content. Return internal-link recommendations only through suggested_internal_link_ids, selecting only IDs supplied in allowed_internal_links; do not render those links in content. Produce a useful, publication-ready article structure that covers the approved topic sufficiently without repetitive padding. Never stop mid-sentence or mid-section; finish with a complete final paragraph that ends naturally for the reader. Adapt the structure to the opportunity. For an informational medical topic, ordinarily cover a concise introduction, key signs or symptoms, when evaluation may be appropriate, relevant next steps or evaluation context, and a concise conclusion. Prefer concrete, useful explanations supported by the supplied context and safe general medical knowledge, choosing only details relevant to the opportunity—for example, what a category means, major approaches, evaluation considerations, important distinctions, limitations, uncertainty, or reasonable questions for a qualified professional. Concrete does not mean speculative. State important medical limitations clearly where appropriate, but do not repeat the same disclaimer or caution in multiple sections. Professional-evaluation guidance should appear where contextually useful, not mechanically throughout the article. Avoid repeatedly saying general information, broad category, educational only, not medical advice, or consult a healthcare professional once the point is established. Use direct sentences, varied structure, descriptive headings, concise paragraphs, natural transitions, and useful information early. Avoid formulaic filler such as it is important to note, this article will explore, when it comes to, in conclusion, repetitive section summaries, or a conclusion that merely restates the introduction. Do not mention AI generation, draft status, human editorial review, internal workflow, or publication process in reader-facing content. Do not diagnose, give personalized medical advice, promise outcomes, invent studies, statistics, success rates, efficacy comparisons, doctors, clinics, services, treatments, locations, credentials, candidacy claims, contraindications, citations, or references. Never claim a supplied clinic or doctor performs a procedure unless the context explicitly supports it. Use cautious educational language and omit unsupported specifics.';
	}

	/** @return array<string,mixed> */
	public static function schema(): array {
		return array(
			'type' => 'object', 'additionalProperties' => false,
			'required' => array( 'title', 'slug', 'excerpt', 'content', 'seo_title', 'meta_description', 'suggested_internal_link_ids' ),
			'properties' => array(
				'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
				'slug' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
				'excerpt' => array( 'type' => 'string', 'minLength' => 20, 'maxLength' => 600 ),
				'content' => array( 'type' => 'string', 'minLength' => 200, 'maxLength' => 20000 ),
				'seo_title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
				'meta_description' => array( 'type' => 'string', 'minLength' => 20, 'maxLength' => 320 ),
				'suggested_internal_link_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'maxItems' => 10 ),
			),
		);
	}
}
