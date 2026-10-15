<?php

namespace App\Services\TopUp;

use App\Models\Game;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One definition of "valid player details" for a game, shared by the player check now and by
 * order creation in Phase 7. Admin-typed rules were already limited to a safe whitelist.
 */
final class PlayerFields
{
    /**
     * @param  array<string,mixed>  $input  the "fields" object sent by the browser
     */
    public static function validator(Game $game, array $input): ValidatorContract
    {
        $rules = [];
        $names = [];

        foreach ($game->fields as $field) {
            $key = 'fields.'.$field->provider_field_name;

            $rule = [
                $field->required ? 'required' : 'nullable',
                $field->type === 'number' ? 'numeric' : 'string',
                'max:100',
            ];

            if ($field->type === 'select' && ! empty($field->options)) {
                $rule[] = Rule::in($field->options);
            }

            if (filled($field->validation_rules)) {
                foreach (explode('|', (string) $field->validation_rules) as $extra) {
                    $extra = trim($extra);
                    if ($extra !== '') {
                        $rule[] = $extra;
                    }
                }
            }

            $rules[$key] = $rule;
            $names[$key] = $field->label;
        }

        return Validator::make(['fields' => $input], $rules, [], $names);
    }

    /**
     * Only the fields this game defines, trimmed, empty ones dropped.
     *
     * @param  array<string,mixed>  $validated  result of $validator->validated()
     * @return array<string,string>
     */
    public static function clean(Game $game, array $validated): array
    {
        $clean = [];

        foreach ($game->fields as $field) {
            $value = $validated['fields'][$field->provider_field_name] ?? null;

            if ($value !== null && $value !== '') {
                $clean[$field->provider_field_name] = trim((string) $value);
            }
        }

        return $clean;
    }
}