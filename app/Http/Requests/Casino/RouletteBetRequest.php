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
        return [
            'bet_type' => ['required', 'string'],
            'numbers' => ['present', 'array'],
            'numbers.*' => ['integer', 'min:0', 'max:36'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
