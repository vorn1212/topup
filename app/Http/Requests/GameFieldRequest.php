<?php

namespace App\Http\Requests;

use App\Support\GameFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameFieldRequest extends FormRequest
{
    /** Keeps field errors separate from the main game form. */
    protected $errorBag = 'field';

    public function authorize(): bool
    {
        return $this->user()?->can('games.manage') ?? false;
    }

    public function rules(): array
    {
        $game = $this->route('game');
        $field = $this->route('field');

        return [
            'provider_field_name' => [
                'required', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
                Rule::unique('game_fields', 'provider_field_name')
                    ->where('game_id', $game?->id)
                    ->ignore($field?->id),
            ],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['text', 'number', 'select'])],
            'required' => ['nullable', 'boolean'],
            'validation_rules' => [
                'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! GameFieldRules::isValid((string) $value)) {
                        $fail('Only simple rules are allowed, for example: digits_between:5,15 or alpha_num|max:20.');
                    }
                },
            ],
            'options' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $this->input('type') === 'select')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider_field_name.regex' => 'Field name must start with a letter and contain only letters, numbers and underscores.',
            'provider_field_name.unique' => 'This game already has a field with that name.',
            'options.required' => 'List at least one option (comma separated) for a select field.',
        ];
    }
}