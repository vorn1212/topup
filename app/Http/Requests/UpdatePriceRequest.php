<?php

namespace App\Http\Requests;

use App\Enums\MarkupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pricing.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'provider_cost' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'markup_type' => ['required', Rule::enum(MarkupType::class)],
            'markup_value' => ['required', 'regex:/^-?\d{1,10}(\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'confirm_loss' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider_cost.regex' => 'Enter the cost as a number with at most 4 decimals.',
            'markup_value.regex' => 'Enter a number with at most 4 decimals.',
        ];
    }
}