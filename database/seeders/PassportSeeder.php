<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Laravel\Passport\ClientRepository;
use RuntimeException;

class PassportSeeder extends Seeder
{
    /**
     * Create the personal access client the API login issues tokens with, once.
     */
    public function run(ClientRepository $clients): void
    {
        $provider = Config::string('auth.guards.api.provider');

        try {
            $clients->personalAccessClient($provider);
        } catch (RuntimeException) {
            $clients->createPersonalAccessGrantClient(Config::string('app.name'), $provider);
        }
    }
}
