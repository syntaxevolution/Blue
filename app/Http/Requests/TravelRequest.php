<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for travel actions from both the Inertia web
 * controller and the REST API controller. Single source of truth —
 * keeps the two layers in lockstep.
 */
class TravelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', 'in:n,s,e,w'],
        ];
    }
}
