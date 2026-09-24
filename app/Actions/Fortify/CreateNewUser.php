<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\TeamName;
use Illuminate\Http\UploadedFile;
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
     * joins that team directly. Everyone else is a new landlord: they must
     * upload a valid ID and their team waits for Super Admin approval.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $invitation = $this->pendingInvitationFor($input);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invitation' => ['nullable', 'string'],
            'business_name' => ['nullable', 'string', 'max:255', new TeamName],
            ...($invitation ? [] : self::verificationRules()),
        ], self::verificationMessages())->validate();

        return DB::transaction(function () use ($input, $invitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($invitation) {
                $this->acceptTeamInvitation->handle($user, $invitation);
            } else {
                $team = $this->createTeam->handle($user, $input['business_name'] ?? $user->name."'s Team", isPersonal: true);

                /** @var UploadedFile $idFile */
                $idFile = $input['verification_id'];

                $team->forceFill([
                    'verification_id_path' => $idFile->store("landlord-ids/{$team->id}", config('filesystems.sensitive_disk')),
                    'verification_submitted_at' => now(),
                ])->save();
            }

            return $user;
        });
    }

    /**
     * Validation rules for the valid ID a new landlord submits.
     *
     * @return array<string, array<int, string>>
     */
    public static function verificationRules(): array
    {
        return [
            'verification_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function verificationMessages(): array
    {
        return [
            'verification_id.required' => __('Upload a valid ID so we can confirm you are a real landlord.'),
            'verification_id.mimes' => __('The ID must be a JPG, PNG or PDF file.'),
            'verification_id.max' => __('The ID must be 5 MB or smaller.'),
            'consent.accepted' => __('Confirm that you agree to share your ID for verification.'),
        ];
    }

    /**
     * Find the pending invitation the given registration input satisfies, if any.
     *
     * @param  array<string, mixed>  $input
     */
    private function pendingInvitationFor(array $input): ?TeamInvitation
    {
        if (empty($input['invitation']) || ! is_string($input['email'] ?? null)) {
            return null;
        }

        return TeamInvitation::query()
            ->pending()
            ->where('code', $input['invitation'])
            ->whereRaw('LOWER(email) = ?', [Str::lower($input['email'])])
            ->first();
    }
}
