<?php

namespace Tests\Unit;

use App\Support\GameFieldRules;
use PHPUnit\Framework\TestCase;

class GameFieldRulesTest extends TestCase
{
    public function test_simple_rules_are_accepted(): void
    {
        foreach ([null, '', 'digits_between:5,15', 'required|numeric|min:3|max:20', 'alpha_num|max:20', 'in:Asia,Europe'] as $rules) {
            $this->assertTrue(GameFieldRules::isValid($rules), 'Should accept: '.var_export($rules, true));
        }
    }

    public function test_dangerous_or_unknown_rules_are_rejected(): void
    {
        foreach ([
            'exists:users,email',
            'unique:users,email',
            'regex:/^(a+)+$/',
            'file',
            'required|exists:users,id',
            'digits_between:5,15;drop',
            'min:3||max:5',
            'dimensions:min_width=1',
            str_repeat('a', 300),
        ] as $rules) {
            $this->assertFalse(GameFieldRules::isValid($rules), 'Should reject: '.substr($rules, 0, 40));
        }
    }
}