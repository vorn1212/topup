<?php

namespace App\Http\Requests;

use App\Enums\BulkOperation;
use App\Services\Pricing\BulkPricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class BulkPricePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pricing.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:'.BulkPricingService::MAX_PRODUCTS],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'operation' => ['required', Rule::enum(BulkOperation::class)],
            'value' => [
                'required', 'string', 'max:20',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $operation = BulkOperation::tryFrom((string) $this->input('operation'));

                    if ($operation === null) {
                        return;
                    }

                    try {
                        BulkPricingService::assertValue($operation, (string) $value);
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'product_ids.required' => 'Tick at least one product first.',
            'product_ids.min' => 'Tick at least one product first.',
            'product_ids.max' => 'You can change at most '.BulkPricingService::MAX_PRODUCTS.' products at once.',
        ];
    }
}