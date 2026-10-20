<?php

namespace App\Http\Requests;

use App\Enums\CatalogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Product details only. Price fields are deliberately not accepted here (see UpdatePriceRequest).
 */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'provider_offer_id' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'provider_offer_id')
                    ->where('provider', 'fazercards')
                    ->where('game_id', $this->route('product')?->game_id)
                    ->ignore($this->route('product')?->id),
            ],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'is_available' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'icon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_width=32,min_height=32'],
            'remove_icon' => ['nullable', 'boolean'],
        ];
    }
}
