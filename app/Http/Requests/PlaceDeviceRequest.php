<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for toolbox "place sabotage device" actions.
 * Consumed by Web\MapController::placeDevice and Api\V1\MapController::placeDevice.
 */
class PlaceDeviceRequest extends FormRequest
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
            // Device key is validated semantically inside SabotageService
            // (must exist in items_catalog with a deployable_sabotage
            // effect). Validating here only guards against obviously
            // malformed input.
            'item_key' => ['required', 'string', 'max:64'],
        ];
    }
}
