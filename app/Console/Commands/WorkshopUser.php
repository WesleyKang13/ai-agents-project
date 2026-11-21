<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class WorkshopUser extends Command
{
    protected $signature = 'workshop:user {email? : Email for your private local login}';

    protected $description = 'Create a local workshop login without public registration';

    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->ask('Email');
        $name = $this->ask('Name');
        $password = $this->secret('Password (at least 12 characters)');
        $confirmation = $this->secret('Confirm password');
        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation], ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:80'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create(['email' => $email, 'name' => $name, 'password' => $password]);
        $this->info('Local account created. Sign in through the workshop.');

        return self::SUCCESS;
    }
}
