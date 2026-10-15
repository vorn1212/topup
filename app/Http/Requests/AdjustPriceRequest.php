<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustPriceRequest extends FormRequest
{
    public const STEPS = ['0.10', '-0.10', '0.50', '-0.50', '1.00', '-1.00'];

    public function authorize(): bool
    {
        return $this->user()?->can('pricing.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'delta' => ['required', Rule::in(self::STEPS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}