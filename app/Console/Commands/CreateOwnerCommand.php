<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first admin owner interactively.
 *
 * The password is only ever read from a hidden prompt. It is never accepted
 * as an argument, never defaulted, and never echoed back — so it cannot end
 * up in shell history, log output, or this repository.
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'iglf:create-owner';

    protected $description = 'Create an owner account for the admin panel (interactive)';

    public function handle(): int
    {
        $name = (string) $this->ask('Name');
        $email = (string) $this->ask('Email');
        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Confirm password (hidden)');

        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ]
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
            'password' => Hash::make($password),
            'role' => User::ROLE_OWNER,
            'is_active' => true,
        ]);

        // ⛔ 只回報 email 與角色，絕不輸出密碼。
        $this->info("Owner created: {$user->email} (role: {$user->role})");

        return self::SUCCESS;
    }
}
