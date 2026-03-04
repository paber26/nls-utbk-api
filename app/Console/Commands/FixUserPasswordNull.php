<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class FixUserPasswordNull extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:fix-password-null';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update password user yang masih NULL menggunakan nomor whatsapp';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Mencari user dengan password NULL...");

        $count = 0;

        User::whereNull('password')
            ->chunk(100, function ($users) use (&$count) {

                foreach ($users as $user) {

                    if (!$user->whatsapp) {
                        $this->warn("User ID {$user->id} tidak memiliki whatsapp, dilewati.");
                        continue;
                    }

                    $user->update([
                        'password' => Hash::make($user->whatsapp)
                    ]);

                    $count++;
                }
            });

        $this->info("Selesai. Total password diperbaiki: {$count}");
    }
}