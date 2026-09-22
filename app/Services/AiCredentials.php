<?php

namespace App\Services;

use App\Enums\AiProvider;
use App\Models\OrganizationProfile;
use App\Models\Team;

/**
 * Which provider a given organization's run should go to, and on whose key.
 *
 * An organization's own configuration always wins. The fallback exists for
 * local development only — in production an organization that has not set a
 * provider gets placeholder output rather than quietly spending the host's
 * money.
 */
readonly class AiCredentials
{
    public function __construct(
        public AiProvider $provider,
        public string $model,
        public ?string $apiKey,
        public ?string $baseUrl,
    ) {}

    public static function forTeam(Team $team): ?self
    {
        $profile = $team->organizationProfile;

        if ($profile instanceof OrganizationProfile && $profile->hasAiConfigured()) {
            return new self(
                provider: $profile->ai_provider,
                model: (string) $profile->resolvedModel(),
                apiKey: $profile->ai_api_key,
                baseUrl: $profile->resolvedBaseUrl(),
            );
        }

        return self::fallback();
    }

    public static function fallback(): ?self
    {
        $provider = AiProvider::tryFrom((string) config('ai.fallback.provider'));
        $apiKey = config('ai.fallback.api_key');

        if ($provider === null || ($provider->needsApiKey() && blank($apiKey))) {
            return null;
        }

        return new self(
            provider: $provider,
            model: (string) (config('ai.fallback.model') ?: $provider->defaultModel()),
            apiKey: is_string($apiKey) ? $apiKey : null,
            baseUrl: (string) (config('ai.fallback.base_url') ?: $provider->defaultBaseUrl()),
        );
    }
}
