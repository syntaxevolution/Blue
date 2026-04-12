<?php

namespace App\Http\Requests\Casino;

use Illuminate\Foundation\Http\FormRequest;

class BlackjackActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:hit,stand,double,surrender,split,insurance'],
        ];
    }
}
