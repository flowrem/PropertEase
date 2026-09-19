<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('announcements'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the announcements page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('announcements'));

    $response->assertOk();
});
