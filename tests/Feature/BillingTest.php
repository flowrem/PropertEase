<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('billing'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the billing page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('billing'));

    $response->assertOk();
});
