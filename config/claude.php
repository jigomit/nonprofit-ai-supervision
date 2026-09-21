<?php

return [

    'api_key' => env('ANTHROPIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | Skill instructions run long and the output is work a person will sign
    | their name to, so this defaults to the most capable model rather than the
    | cheapest one. The dominant cost is output tokens, not the instructions.
    |
    */

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),

    /*
    |--------------------------------------------------------------------------
    | Prompt cache
    |--------------------------------------------------------------------------
    |
    | The skill body is sent as a cached system block. Cache reads cost a tenth
    | of the base rate, so a skill run more than once a window is roughly ten
    | times cheaper — but only while the block stays byte-identical. Nothing
    | organization-specific may be interpolated into it; that context belongs in
    | the message, after the breakpoint. TaskPromptBuilder enforces this.
    |
    */

    'cache_ttl' => env('ANTHROPIC_CACHE_TTL', '1h'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | With no API key configured the app still works end to end: runs land in
    | their gate with a placeholder output, so the approval flow can be
    | demonstrated without spending anything.
    |
    */

    'enabled' => env('ANTHROPIC_ENABLED', true),

];
