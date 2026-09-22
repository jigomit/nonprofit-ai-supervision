<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use App\Models\OrganizationProfile;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAiSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ai_provider' => ['required', Rule::enum(AiProvider::class)],
            'ai_model' => ['nullable', 'string', 'max:120'],
            'ai_api_key' => ['nullable', 'string', 'max:500'],
            // A self-hosted address is often plain http on a private network,
            // so https cannot be required here.
            'ai_base_url' => ['nullable', 'string', 'url', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_provider.required' => 'Choose which service should produce your drafts.',
            'ai_base_url.url' => 'That does not look like an address — try something like http://localhost:11434/v1.',
        ];
    }

    /**
     * The attributes to write.
     *
     * An empty key field means "keep what is stored", because the stored key
     * is never sent to the browser and so cannot be re-submitted. The one
     * exception is a change of provider: one service's key is useless at
     * another, so it is dropped rather than left lying encrypted in the row.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = [
            'ai_provider' => $this->provider(),
            'ai_model' => $this->filled('ai_model') ? trim((string) $this->input('ai_model')) : null,
            'ai_base_url' => $this->filled('ai_base_url') ? rtrim(trim((string) $this->input('ai_base_url')), '/') : null,
        ];

        if ($this->filled('ai_api_key')) {
            $settings['ai_api_key'] = trim((string) $this->input('ai_api_key'));
        } elseif ($this->providerChanged()) {
            $settings['ai_api_key'] = null;
        }

        return $settings;
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = $this->provider();

            if ($provider === null) {
                return;
            }

            // Saving cleanly and then failing at the first run is the worst of
            // both: catch a missing key here instead.
            if ($provider->needsApiKey() && ! $this->filled('ai_api_key') && ! $this->hasUsableStoredKey()) {
                $validator->errors()->add('ai_api_key', $provider->label().' needs an API key.');
            }

            if ($provider->needsBaseUrl() && ! $this->filled('ai_base_url') && blank($provider->defaultBaseUrl())) {
                $validator->errors()->add('ai_base_url', $provider->label().' needs the address it is running on.');
            }
        });
    }

    protected function provider(): ?AiProvider
    {
        return AiProvider::tryFrom((string) $this->input('ai_provider'));
    }

    protected function providerChanged(): bool
    {
        return $this->profile()?->ai_provider !== $this->provider();
    }

    protected function hasUsableStoredKey(): bool
    {
        return ! $this->providerChanged() && filled($this->profile()?->ai_api_key);
    }

    protected function profile(): ?OrganizationProfile
    {
        return Team::query()
            ->where('slug', $this->route('current_team'))
            ->first()
            ?->organizationProfile;
    }
}
