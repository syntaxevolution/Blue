<?php

it('dashboard page title contains Clash Wars', function () {
    $response = $this->actingAs(\App\Models\User::factory()->create())->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Dashboard'));
});

it('app name config defaults to Clash Wars', function () {
    // Flush cache
    app()->forgetInstance('config');
    expect(config('app.name'))->not->toBe('Cash Clash');
});

it('welcome page does not contain the legacy Cash Clash branding', function () {
    $response = $this->get('/');
    $response->assertOk();
    expect($response->getContent())->not->toContain('Cash Clash');
});
