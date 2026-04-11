<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimUsernameRequest;
use Illuminate\Http\JsonResponse;

class UsernameController extends Controller
{
    public function claim(ClaimUsernameRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'name' => $request->validated()['name'],
            'name_claimed_at' => now(),
        ])->save();

        return response()->json([
            'name' => $user->name,
            'name_claimed_at' => $user->name_claimed_at?->toIso8601String(),
        ]);
    }
}
