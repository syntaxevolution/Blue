<?php

use App\Models\User;
use App\Rules\UniqueUsername;
use Illuminate\Support\Facades\Validator;

function validateName(string $value): array
{
    $v = Validator::make(['name' => $value], [
        'name' => [new UniqueUsername],
    ]);
    $v->passes();

    return $v->errors()->get('name');
}

it('accepts a compliant username', function () {
    expect(validateName('ValidUser1'))->toBe([]);
});

it('rejects usernames shorter than 5 chars', function () {
    expect(validateName('abc'))->not->toBe([]);
});

it('rejects usernames longer than 15 chars', function () {
    expect(validateName('ABCDEFGHIJKLMNOP'))->not->toBe([]);
});

it('rejects non-alphanumeric usernames', function () {
    expect(validateName('user_name'))->not->toBe([]);
    expect(validateName('user name'))->not->toBe([]);
    expect(validateName('user-name'))->not->toBe([]);
});

it('rejects case-insensitive duplicates', function () {
    User::factory()->create(['name' => 'TestUser1']);

    expect(validateName('testuser1'))->not->toBe([]);
    expect(validateName('TESTUSER1'))->not->toBe([]);
    expect(validateName('TestUser1'))->not->toBe([]);
});

it('allows a different alphanumeric username', function () {
    User::factory()->create(['name' => 'TestUser1']);

    expect(validateName('DifferentName'))->toBe([]);
});
