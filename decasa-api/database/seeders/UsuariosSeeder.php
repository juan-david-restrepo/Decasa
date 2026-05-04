<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuariosSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('usuarios')->insert([
            [
                'nombre'            => 'Admin Supervisor',
                'email'             => 'admin@decasa.com',
                'password'          => Hash::make('Decasa2024!'),
                'rol'               => 'supervisor',
                'tienda_default_id' => 1,
                'activo'            => true,
            ],
            [
                'nombre'            => 'Vendedor Demo',
                'email'             => 'vendedor@decasa.com',
                'password'          => Hash::make('Decasa2024!'),
                'rol'               => 'vendedor',
                'tienda_default_id' => 1,
                'activo'            => true,
            ],
        ]);
    }
}
