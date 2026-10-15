<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canTransact() ?? false;
    }

    public function rules(): array
    {
        return [
            // Up to 2 decimals: the ABA payment page works in cents.
            'amount' => ['required', 'string', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('amount')) {
                    return;
                }

                $amount = (string) $this->input('amount');
                $min = (string) config('topup.deposit.min');
                $max = (string) config('topup.deposit.max');

                if (Money::cmp($amount, $min) < 0 || Money::cmp($amount, $max) > 0) {
                    $validator->errors()->add(
                        'amount',
                        sprintf('Deposit must be between %s and %s.', Money::format($min), Money::format($max))
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter a valid amount with at most 2 decimals, for example 10 or 10.50.',
        ];
    }
}