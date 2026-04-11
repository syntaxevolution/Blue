<?php

namespace App\Http\Requests;

use App\Rules\UniqueUsername;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One-time username claim. A user arriving without a claimed name
 * (legacy accounts, or any path that skipped the registration form)
 * must choose one before any game routes become accessible.
 *
 * Ignores their own ID so if the uniqueness check somehow sees the
 * same value twice (e.g., double-submit), it's not rejected as a
 * collision against themselves.
 */
class ClaimUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->hasClaimedUsername();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', new UniqueUsername($this->user()?->id)],
        ];
    }
}
