<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canTransact() ?? false;
    }

    public function rules(): array
    {
        return [
            'game' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9\-]+$/'],
            'product_id' => ['required', 'integer', 'min:1'],
            'fields' => ['nullable', 'array', 'max:10'],
            'fields.*' => ['nullable', 'string', 'max:100'],
            // Lenient on purpose: OrderService treats an odd token as "no double-submit protection"
            // and a wrong expected price as "the price changed", with a visible message.
            'request_token' => ['nullable', 'string', 'max:64'],
            'expected_price' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Please choose a package.',
            'product_id.integer' => 'Please choose a package.',
        ];
    }
}