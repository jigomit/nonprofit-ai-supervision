<?php

namespace App\Http\Requests;

use App\Enums\BudgetBand;
use App\Enums\ExpertGatePolicy;
use App\Models\Skill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOrganizationProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['nullable', 'string', 'max:100'],
            'ein' => ['nullable', 'string', 'regex:/^\d{2}-\d{7}$/'],
            'state_of_incorporation' => ['nullable', 'string', 'size:2', 'alpha'],
            'fiscal_year_end_month' => ['nullable', 'integer', 'between:1,12'],
            'budget_band' => ['nullable', Rule::enum(BudgetBand::class)],
            'mission' => ['nullable', 'string', 'max:2000'],
            'expert_gate_policy' => ['required', Rule::enum(ExpertGatePolicy::class)],
            'collections' => ['array'],
            // Only a real special collection may be opted into. Core categories
            // are always included, so naming one here is a mistake, not a choice.
            'collections.*' => [
                'string',
                Rule::in(Skill::query()->where('is_core', false)->distinct()->pluck('category')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ein.regex' => 'An EIN looks like 12-3456789.',
            'state_of_incorporation.size' => 'Use the two-letter state code, such as OH.',
            'collections.*.in' => 'That is not one of the available special collections.',
            'expert_gate_policy.required' => 'Choose what should happen when work needs a credentialed professional.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ein' => $this->filled('ein') ? trim((string) $this->input('ein')) : null,
            'state_of_incorporation' => $this->filled('state_of_incorporation')
                ? strtoupper(trim((string) $this->input('state_of_incorporation')))
                : null,
            'collections' => array_values(array_unique((array) $this->input('collections', []))),
        ]);
    }
}
