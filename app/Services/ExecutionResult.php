<?php

namespace App\Services;

/**
 * What one model call produced, and what it cost.
 *
 * `usage` is kept because a zero cache-read across repeated runs of the same
 * task is the signal that something has started varying inside the cached
 * block — a fault that costs money quietly rather than breaking anything.
 */
readonly class ExecutionResult
{
    /**
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public string $output,
        public string $model,
        public array $usage = [],
    ) {}

    public function cacheReadTokens(): int
    {
        return (int) ($this->usage['cache_read_input_tokens'] ?? 0);
    }

    public function servedFromCache(): bool
    {
        return $this->cacheReadTokens() > 0;
    }
}
