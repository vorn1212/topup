<?php

namespace App\Http\Requests;

use App\Enums\CatalogStatus;
use App\Enums\MarkupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') && $this->user()?->can('pricing.manage');
    }

    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:products,sku'],
            'description' => ['nullable', 'string', 'max:2000'],
            'provider_offer_id' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'provider_offer_id')
                    ->where('provider', 'fazercards')
                    ->where('game_id', $this->input('game_id')),
            ],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'is_available' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'provider_cost' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'markup_type' => ['required', Rule::enum(MarkupType::class)],
            'markup_value' => ['required', 'regex:/^-?\d{1,10}(\.\d{1,4})?$/'],
            'confirm_loss' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider_cost.regex' => 'Enter the cost as a number with at most 4 decimals.',
            'markup_value.regex' => 'Enter a number with at most 4 decimals.',
            'sku.regex' => 'SKU may contain only letters, numbers, dashes and underscores.',
        ];
    }
}