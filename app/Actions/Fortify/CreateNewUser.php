<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private CreateTeam $createTeam,
        private AcceptTeamInvitation $acceptTeamInvitation,
    ) {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * A user registering through a valid, pending invitation for their email
     * joins that team directly instead of getting their own personal team.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invitation' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $invitation = $this->pendingInvitationFor($input);

            if ($invitation) {
                $this->acceptTeamInvitation->handle($user, $invitation);
            } else {
                $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);
            }

            return $user;
        });
    }

    /**
     * Find the pending invitation the given registration input satisfies, if any.
     *
     * @param  array<string, string>  $input
     */
    private function pendingInvitationFor(array $input): ?TeamInvitation
    {
        if (empty($input['invitation'])) {
            return null;
        }

        return TeamInvitation::query()
            ->pending()
            ->where('code', $input['invitation'])
            ->whereRaw('LOWER(email) = ?', [Str::lower($input['email'])])
            ->first();
    }
}
