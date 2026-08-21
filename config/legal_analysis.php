<?php

return [
    'prompt_version' => 'legal-analysis-v2',
    'retrieval_version' => 'lexical-structural-v2',
    'citation_validator_version' => 'citation-v2',
    'timeout_seconds' => 180,

    'retrieval' => [
        'context_budget_chars' => 30000,
        'top_k' => 30,
        'max_fragment_chars' => 5000,
        'min_fragment_chars' => 30,
        'min_term_length' => 4,
        'max_term_frequency' => 3,
        'max_exact_phrases' => 20,
        'reliable_locator_matches' => 2,
        'length_normalization_chars' => 1800,
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
