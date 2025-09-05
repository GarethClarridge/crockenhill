<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateApiToken extends Command
{
    protected $signature = 'api:create-token {email} {name=API Token} {--abilities=*}';

    protected $description = 'Create an API token for a user';

    public function handle()
    {
        $email = $this->argument('email');
        $tokenName = $this->argument('name');
        $abilities = $this->option('abilities') ?: ['*'];

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email {$email} not found.");

            return 1;
        }

        $token = $user->createToken($tokenName, $abilities);

        $this->info('API Token created successfully!');
        $this->line("Token: {$token->plainTextToken}");
        $this->warn("Save this token securely - you won't be able to see it again!");

        return 0;
    }
}
