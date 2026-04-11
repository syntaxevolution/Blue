<?php

namespace App\Rules;

use App\Domain\Config\GameConfigResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates that a username is:
 *   - alphanumeric only
 *   - between config('game.username.min_length') and max_length chars
 *   - not already taken by any other user (case-insensitive)
 *
 * Both the pattern and the length bounds live in config/game.php so
 * rules can be tuned live without a deploy.
 */
class UniqueUsername implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        /** @var GameConfigResolver $config */
        $config = app(GameConfigResolver::class);

        $min = (int) $config->get('username.min_length');
        $max = (int) $config->get('username.max_length');
        $pattern = (string) $config->get('username.pattern');

        if ($pattern === '' || preg_match($pattern, $value) !== 1) {
            $fail("The :attribute must be {$min}-{$max} alphanumeric characters only.");

            return;
        }

        $query = DB::table('users')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)]);

        if ($this->ignoreUserId !== null) {
            $query->where('id', '!=', $this->ignoreUserId);
        }

        if ($query->exists()) {
            $fail('This username is already taken.');
        }
    }
}
