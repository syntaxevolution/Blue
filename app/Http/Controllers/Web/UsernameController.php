<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimUsernameRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

/**
 * Handles the one-time "claim your display name" flow.
 *
 * The frontend renders ClaimUsernameModal.vue over the whole UI as
 * soon as an authenticated user arrives without a claimed name. All
 * other game actions are blocked until claim completes (via the
 * RequireClaimedUsername middleware).
 */
class UsernameController extends Controller
{
    public function claim(ClaimUsernameRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'name' => $request->validated()['name'],
            'name_claimed_at' => now(),
        ])->save();

        return Redirect::back()->with('flash', ['username_claimed' => $user->name]);
    }
}
