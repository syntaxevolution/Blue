<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for drill actions.
 */
class DrillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public function rules(): array
    {
        return [
            'grid_x' => ['required', 'integer', 'min:0', 'max:4'],
            'grid_y' => ['required', 'integer', 'min:0', 'max:4'],
        ];
    }
}
