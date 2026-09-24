<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createSuperAdmin(): User
{
    $admin = User::create([
        'name' => 'Site Admin',
        'email' => 'admin-'.uniqid().'@example.com',
        'password' => 'password',
    ]);

    $admin->is_super_admin = true;
    $admin->save();

    return $admin;
}

test('a guest is redirected to login when visiting admin routes', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('admin.landlords'))->assertRedirect(route('login'));
});

test('a non super admin gets a 403 on admin routes', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    $this->get(route('admin.dashboard'))->assertForbidden();
    $this->get(route('admin.landlords'))->assertForbidden();
});

test('a super admin can view the admin routes', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin);

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.landlords'))->assertOk();
});

test('a teamless super admin can load settings without the layout crashing', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin);

    $this->get(route('profile.edit'))->assertOk();
});

test('is_super_admin cannot be mass assigned when creating a user', function () {
    $user = User::create([
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'password',
        'is_super_admin' => true,
    ]);

    expect($user->fresh()->is_super_admin)->toBeFalse();
});

test('is_super_admin cannot be set through the registration form', function () {
    Storage::fake(config('filesystems.sensitive_disk'));

    $this->post(route('register.store'), [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'business_name' => "Someone's Apartments",
        'verification_id' => UploadedFile::fake()->image('id.jpg'),
        'consent' => '1',
        'is_super_admin' => true,
    ]);

    $user = User::where('email', 'someone@example.com')->firstOrFail();

    expect($user->is_super_admin)->toBeFalse();
});

test('is_super_admin cannot be set through a profile update payload', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire\Livewire::test('pages::settings.profile')
        ->set('name', 'Updated Name')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    expect($user->fresh()->is_super_admin)->toBeFalse();
});
