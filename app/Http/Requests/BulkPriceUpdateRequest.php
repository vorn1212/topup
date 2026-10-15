<?php

namespace App\Http\Requests;

class BulkPriceUpdateRequest extends BulkPricePreviewRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'confirm' => ['accepted'],
            'confirm_loss' => ['nullable', 'boolean'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'confirm.accepted' => 'Tick the box to confirm you reviewed the changes.',
        ]);
    }
}