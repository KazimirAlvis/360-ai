<?php

namespace Global360\AI\Generation;

final class DraftRequest {
	public static function instructions(): string {
		return 'Write one complete educational WordPress draft for the approved opportunity and supplied context. Return only the required structured fields. The content field must contain attribute-free HTML using only <p>, <h2>, <h3>, <ul>, <ol>, <li>, <strong>, and <em>. Do not use any other HTML tags or attributes, including <a>, href, inline styles, classes, IDs, data-* attributes, event handlers, H1, blockquote, div, span, table, br, hr, b, or i. Do not include Markdown, fenced code blocks, links, or URLs in content. Do not insert hyperlinks or <a> tags into the generated content. Return internal-link recommendations only through suggested_internal_link_ids, selecting only IDs supplied in allowed_internal_links; do not render those links in content. Produce a useful, publication-ready article structure that covers the approved topic sufficiently without repetitive padding. Never stop mid-sentence or mid-section; finish with a complete final paragraph. Adapt the structure to the opportunity. For an informational medical topic, ordinarily cover a concise introduction, key signs or symptoms, when evaluation may be appropriate, relevant next steps or evaluation context, and a concise conclusion. Do not diagnose, give personalized medical advice, promise outcomes, invent studies, statistics, success rates, efficacy comparisons, doctors, clinics, services, treatments, locations, credentials, candidacy claims, citations, or references. Never claim a supplied clinic or doctor performs a procedure unless the context explicitly supports it. Use cautious educational language, omit unsupported specifics, and state that the draft requires human editorial review.';
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
