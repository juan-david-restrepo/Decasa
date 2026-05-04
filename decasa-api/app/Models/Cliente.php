<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'cedula',
        'telefono',
        'email',
        'direccion',
        'canal_pref',
    ];

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'cliente_id');
    }
}
