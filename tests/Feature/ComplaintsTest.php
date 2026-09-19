<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('complaints'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the complaints page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('complaints'));

    $response->assertOk();
});
