<?php

namespace App\Http\Requests\Casino;

use Illuminate\Foundation\Http\FormRequest;

class SpinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'in:akzar_cash,oil_barrels'],
            'bet' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
