<?php

namespace App\Http\Requests;

use App\Enums\MarkupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user
            && $user->can('provider.manage')
            && $user->can('pricing.manage')
            && $user->can('products.manage');
    }

    public function rules(): array
    {
        return [
            // A single fixed price for every offer makes no sense, so only markup modes are offered.
            'markup_type' => ['required', Rule::in([MarkupType::PercentMarkup->value, MarkupType::FixedMarkup->value])],
            'markup_value' => ['required', 'regex:/^-?\d{1,6}(\.\d{1,4})?$/'],
            'make_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'markup_value.regex' => 'Enter a number with at most 4 decimals, for example 20 (percent) or 0.25 (USD).',
        ];
    }
}