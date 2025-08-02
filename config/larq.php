<?php

return [
    'host' => env('LARQ_HOST', 'http://localhost:6444'),
    'api_key' => env('LARQ_API_KEY'),

    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'text-embedding-ada-002'),

    'gemini_api_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_EMBEDDING_MODEL', 'models/embedding-001'),
    
    // AI Service Configuration
    'ai_timeout' => env('AI_REQUEST_TIMEOUT', 30),
    'ai_max_tokens' => env('AI_MAX_TOKENS', 1000),
    'ai_temperature' => env('AI_TEMPERATURE', 0.3),
    
    // Vector Service Configuration
    'vector_segment_max_words' => env('VECTOR_SEGMENT_MAX_WORDS', 300),
    'vector_segment_overlap_words' => env('VECTOR_SEGMENT_OVERLAP_WORDS', 50),
    'vector_similarity_threshold' => env('VECTOR_SIMILARITY_THRESHOLD', 0.2),
    'vector_search_limit' => env('VECTOR_SEARCH_LIMIT', 10),
];