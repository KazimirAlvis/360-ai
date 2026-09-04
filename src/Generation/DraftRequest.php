<?php

namespace Global360\AI\Generation;

final class DraftRequest {
	public static function instructions(): string {
		return 'Write one educational WordPress draft for the approved opportunity and supplied context. Return only the required structured fields. Use clean semantic HTML with paragraphs, h2, h3, ul, ol, li, strong, and em; do not include an h1, CSS, JavaScript, iframes, forms, shortcodes, or Markdown fences. Do not diagnose, give personalized medical advice, promise outcomes, invent studies, statistics, success rates, efficacy comparisons, doctors, clinics, services, treatments, locations, credentials, candidacy claims, citations, or references. Never claim a supplied clinic or doctor performs a procedure unless the context explicitly supports it. Use cautious educational language, omit unsupported specifics, and state that the draft requires human editorial review. Suggested internal-link IDs must come only from allowed_internal_links.';
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
