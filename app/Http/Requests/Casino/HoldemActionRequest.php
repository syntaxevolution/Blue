<?php

namespace App\Http\Requests\Casino;

use Illuminate\Foundation\Http\FormRequest;

class HoldemActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:fold,check,call,raise,all_in'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
