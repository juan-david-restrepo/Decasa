<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenItem extends Model
{
    protected $table = 'orden_items';

    public $timestamps = false;

    protected $fillable = [
        'orden_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
        'es_personalizado',
        'specs_personalizacion',
        'fecha_entrega_prom',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario'       => 'decimal:2',
            'es_personalizado'      => 'boolean',
            'specs_personalizacion' => 'array',
            'fecha_entrega_prom'    => 'date',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function produccion()
    {
        return $this->hasOne(Produccion::class, 'orden_item_id');
    }
}
