<?php

return [
    'prompt_version' => 'legal-drafting-v4',
    'retrieval_version' => 'structural-bm25-v3',
    'citation_validator_version' => 'citation-v3',
    'drafting_validator_version' => 'amendment-v1',
    'timeout_seconds' => 180,

    'retrieval' => [
        'context_budget_chars' => 30000,
        'structural_reserved_chars' => 18000,
        'optional_relevance_chars' => 12000,
        'top_k' => 30,
        'max_fragment_chars' => 5000,
        'min_fragment_chars' => 30,
        'min_term_length' => 4,
        'max_term_frequency' => 3,
        'max_exact_phrases' => 20,
        'reliable_locator_matches' => 2,
        'length_normalization_chars' => 1800,
        'bm25' => [
            'k1' => 1.2,
            'b' => 0.75,
        ],
        'weights' => [
            'term_frequency' => 1.0,
            'coverage' => 12.0,
            'exact_phrase' => 8.0,
            'article_reference' => 14.0,
            'paragraph_reference' => 10.0,
            'subparagraph_reference' => 8.0,
            'length_penalty' => 0.2,
        ],
    ],

    'discovery' => [
        'outline_budget_chars' => 50000,
        'max_candidates' => 16,
        'max_search_queries' => 12,
        'required_fragment_score' => 1000,
    ],

    'draft_package' => [
        'schema_version' => 'draft-package-v1',
        'generator_version' => 'legal-draft-pack-v1',
        'justification_prompt_version' => 'draft-package-justification-v1',
        'justification_validator_version' => 'draft-package-justification-v1',
        'timeout_seconds' => 180,
        'current_text_absent_label' => 'Отсутствует',
    ],

    'stop_words' => [
        'ru' => [
            'будет', 'были', 'было', 'быть', 'весь', 'всех', 'данный', 'должен',
            'если', 'который', 'между', 'может', 'необходимо', 'после', 'перед',
            'провести', 'также', 'такой', 'только', 'этого', 'этой', 'этот',
        ],
        'kz' => [
            'арқылы', 'барлық', 'бойынша', 'болып', 'болса', 'болуы', 'дейін',
            'және', 'кейін', 'кезінде', 'қажет', 'туралы', 'үшін', 'осы',
        ],
    ],
];
