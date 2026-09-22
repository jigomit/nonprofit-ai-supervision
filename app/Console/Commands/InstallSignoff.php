<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first account.
 *
 * Sign-up is closed by default, invitations need somebody to send them, and
 * that left a fresh install with no documented way in at all. This is that way
 * in — and the only one, so it refuses to run once an account exists rather
 * than becoming a quiet route to a second owner.
 */
class InstallSignoff extends Command
{
    protected $signature = 'signoff:install {--name=} {--email=}';

    protected $description = 'Create the first owner account and its organization';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->error('This application already has an account. Invite people from Settings → Teams.');

            return self::FAILURE;
        }

        if (Skill::query()->doesntExist()) {
            $this->components->warn('No tasks are imported yet. After this, clone the library and run `php artisan skills:import`.');
            $this->newLine();
        }

        $name = (string) ($this->option('name') ?: text(
            label: 'Your name',
            required: true,
        ));

        $email = (string) ($this->option('email') ?: text(
            label: 'Your email address',
            required: true,
            validate: fn (string $value) => Validator::make(['email' => $value], [
                'email' => ['email'],
            ])->fails() ? 'That is not an email address.' : null,
        ));

        // Never accepted as an option: a password passed on the command line
        // is a password in the shell history.
        $secret = password(
            label: 'Choose a password',
            required: true,
            validate: function (string $value) {
                $rules = Password::default();

                return Validator::make(['password' => $value], [
                    'password' => [$rules],
                ])->fails()
                    ? 'That password is too weak for this environment.'
                    : null;
            },
        );

        $organization = (string) text(
            label: 'What is the organization called?',
            default: $name."'s organization",
            required: true,
        );

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($secret),
        ]);

        // The same path registration takes, so the first account is not a
        // special shape nothing else in the application expects.
        app(CreateTeam::class)->handle($user, $organization, isPersonal: true);

        $this->newLine();
        $this->components->info("Signed up {$email} and created {$organization}.");

        $this->components->bulletList([
            'Sign in and fill in the organization profile — the catalogue is empty until you do.',
            'Set an AI provider under Organization → AI provider, or every draft is placeholder text.',
            'Run `php artisan queue:work`, or runs sit queued for ever.',
        ]);

        if (config('signoff.registration')) {
            $this->newLine();
            $this->components->warn('ALLOW_REGISTRATION is true, so anyone can still sign themselves up. Set it to false on a reachable host.');
        }

        return self::SUCCESS;
    }
}
