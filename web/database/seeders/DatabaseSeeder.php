<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@unesp.br'],
            [
                'name'     => 'Administrador SCOM',
                'password' => Hash::make('12345678'),
                'role'     => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'operador@unesp.br'],
            [
                'name'     => 'Operador Planta',
                'password' => Hash::make('12345678'),
                'role'     => 'user',
            ]
        );
    }
}