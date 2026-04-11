<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates profile edits.
 *
 * The `name` field is intentionally NOT present: usernames are claimed
 * once at registration (or via the ClaimUsername flow for legacy accounts)
 * and are then immutable. The form view shows name as read-only.
 *
 * Email remains editable, but any change triggers re-verification via
 * the ProfileController (see MustVerifyEmail on User).
 */
class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
