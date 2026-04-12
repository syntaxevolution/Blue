<?php

namespace App\Http\Requests\Casino;

use Illuminate\Foundation\Http\FormRequest;

class RouletteBetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // max:37 covers the American double-zero pocket (stored as int
        // 37 internally, rendered as "00" in the UI). European tables
        // will still reject 37 server-side via the variant check in
        // RouletteService::validateBetType — this is just the outer
        // form-level guard.
        return [
            'bet_type' => ['required', 'string'],
            'numbers' => ['present', 'array'],
            'numbers.*' => ['integer', 'min:0', 'max:37'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
