<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-super-admin')]
#[Description('Create a new Super Admin account, or promote an existing user by email')]
class CreateSuperAdmin extends Command
{
    use PasswordValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->ask('Email address');

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        return $user ? $this->promote($user) : $this->createNew((string) $email);
    }

    /**
     * Promote an existing user to Super Admin.
     */
    private function promote(User $user): int
    {
        if ($user->is_super_admin) {
            $this->info("{$user->email} is already a Super Admin.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Promote {$user->name} ({$user->email}) to Super Admin?")) {
            return self::SUCCESS;
        }

        $user->is_super_admin = true;
        $user->save();

        $this->info("{$user->email} is now a Super Admin.");

        return self::SUCCESS;
    }

    /**
     * Create a brand new Super Admin account with no team.
     */
    private function createNew(string $email): int
    {
        $name = $this->ask('Name');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            ['name' => ['required', 'string', 'max:255'], 'password' => $this->passwordRules()],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $user->is_super_admin = true;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Created Super Admin {$user->email}.");

        return self::SUCCESS;
    }
}
