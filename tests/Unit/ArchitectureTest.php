<?php

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| These Pest arch() rules enforce the structural invariants from
| technical-ultraplan §3:
|
|   - Domain layer is pure PHP — no HTTP, no Inertia, no Request access
|   - Controllers are thin — no DB/query-builder calls inline
|   - Models never reference controllers or HTTP types
|
| A rule that fails here is a guardrail hit, not a flaky test. Fix by
| moving the misplaced code into its proper layer.
|
*/

arch('domain layer has no HTTP awareness')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Inertia\Inertia',
        'Inertia\Response',
    ]);

arch('domain layer does not reach into controllers')
    ->expect('App\Domain')
    ->not->toUse('App\Http\Controllers');

arch('controllers do not touch the query builder directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('models never reference controllers')
    ->expect('App\Models')
    ->not->toUse('App\Http\Controllers');
