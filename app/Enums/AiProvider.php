<?php

namespace App\Enums;

/**
 * Where an organization's drafts are produced.
 *
 * Most providers speak the same `/v1/chat/completions` shape that OpenAI
 * established, so one driver covers nearly all of them and adding another is a
 * config entry rather than code. Anthropic is the exception worth making: its
 * native API supports prompt caching, which is what keeps repeated runs of the
 * same task from paying for the instructions every time.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case XAi = 'xai';
    case Mistral = 'mistral';
    case Meta = 'meta';
    case Ollama = 'ollama';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic (Claude)',
            self::OpenAi => 'OpenAI (ChatGPT)',
            self::XAi => 'xAI (Grok)',
            self::Mistral => 'Mistral',
            self::Meta => 'Meta (Llama)',
            self::Ollama => 'Ollama',
        };
    }

    /**
     * Whether this provider speaks OpenAI's chat-completions shape. Everything
     * that does shares one driver.
     */
    public function isOpenAiCompatible(): bool
    {
        return $this !== self::Anthropic;
    }

    /**
     * Ollama usually runs on the organization's own machine or private server,
     * so it needs an address rather than a key.
     */
    public function needsApiKey(): bool
    {
        return $this !== self::Ollama;
    }

    public function needsBaseUrl(): bool
    {
        return $this === self::Ollama;
    }

    public function defaultModel(): string
    {
        return (string) config("ai.providers.{$this->value}.model");
    }

    public function defaultBaseUrl(): ?string
    {
        $url = config("ai.providers.{$this->value}.base_url");

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** A hint under the model field, so nobody has to guess the spelling. */
    public function modelHint(): string
    {
        return match ($this) {
            self::Anthropic => 'for example claude-opus-5 or claude-sonnet-5',
            self::OpenAi => 'for example gpt-5 or gpt-5-mini',
            self::XAi => 'for example grok-4',
            self::Mistral => 'for example mistral-large-latest',
            self::Meta => 'for example llama-4-maverick',
            self::Ollama => 'whatever you have pulled — for example llama3 or mistral',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'defaultModel' => $provider->defaultModel(),
                'modelHint' => $provider->modelHint(),
                'needsApiKey' => $provider->needsApiKey(),
                'needsBaseUrl' => $provider->needsBaseUrl(),
                'defaultBaseUrl' => $provider->defaultBaseUrl(),
            ],
            self::cases(),
        );
    }
}
