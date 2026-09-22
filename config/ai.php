<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each organization chooses one of these and supplies its own key, so a
    | nonprofit that already pays for ChatGPT or runs Ollama on its own server
    | keeps using what it has — and nobody's runs land on anyone else's bill.
    |
    | Everything except Anthropic speaks OpenAI's chat-completions shape, so
    | adding another provider that does is an entry in this list and nothing
    | more. Anthropic keeps its own driver because its native API supports
    | prompt caching, which is what stops a repeated task paying for its
    | instructions every time.
    |
    */

    'providers' => [
        'anthropic' => [
            'model' => 'claude-opus-5',
            'base_url' => 'https://api.anthropic.com',
        ],
        'openai' => [
            'model' => 'gpt-5',
            'base_url' => 'https://api.openai.com/v1',
        ],
        'xai' => [
            'model' => 'grok-4',
            'base_url' => 'https://api.x.ai/v1',
        ],
        'mistral' => [
            'model' => 'mistral-large-latest',
            'base_url' => 'https://api.mistral.ai/v1',
        ],
        'meta' => [
            'model' => 'llama-4-maverick',
            'base_url' => 'https://api.llama.com/compat/v1',
        ],
        'ollama' => [
            'model' => 'llama3',
            'base_url' => 'http://localhost:11434/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development fallback
    |--------------------------------------------------------------------------
    |
    | Used only when an organization has not configured a provider of its own.
    | Leave it empty in production: an organization with no provider set should
    | get clearly-marked placeholder output, not quietly spend the host's
    | money.
    |
    */

    'fallback' => [
        'provider' => env('AI_FALLBACK_PROVIDER'),
        'api_key' => env('AI_FALLBACK_API_KEY', env('ANTHROPIC_API_KEY')),
        'model' => env('AI_FALLBACK_MODEL'),
        'base_url' => env('AI_FALLBACK_BASE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily run limit
    |--------------------------------------------------------------------------
    |
    | The most runs one organization may start in a day, counting the work list
    | and the calendar together. Each organization spends its own key, so this
    | is not about the host's bill — it is about a loop, a mistake or a stolen
    | account not being able to empty someone's account overnight. An
    | organization can be given its own number on its profile; 0 removes the
    | cap entirely.
    |
    */

    'daily_run_limit' => (int) env('AI_DAILY_RUN_LIMIT', 50),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 16000),

    'timeout' => (int) env('AI_TIMEOUT', 180),

    /*
    |--------------------------------------------------------------------------
    | Local timeout
    |--------------------------------------------------------------------------
    |
    | A laptop generating a few thousand tokens is minutes, not seconds, and a
    | run that times out halfway is worse than one that takes a while.
    |
    */

    'ollama_timeout' => (int) env('AI_OLLAMA_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Prompt cache lifetime (Anthropic only)
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => env('AI_CACHE_TTL', '1h'),

];
