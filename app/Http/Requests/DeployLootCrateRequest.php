<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for the "deploy sabotage loot crate" action.
 * Consumed by Web\LootCrateController::deploy and
 * Api\V1\LootCrateController::deploy.
 *
 * The current tile is always the player's `current_tile_id` — no
 * coordinate params — so the only input is the item key to deploy.
 * Item-key semantic validation (must be a deployable_loot_crate in
 * items_catalog, must be owned) lives inside LootCrateService::place.
 */
class DeployLootCrateRequest extends FormRequest
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
            'item_key' => ['required', 'string', 'max:64'],
        ];
    }
}
