<?php

namespace App\Services;

use App\Events\NuevaNotificacion;
use App\Models\Notificacion;

class NotificacionService
{
    public static function crear(
        string $tipo,
        string $titulo,
        string $mensaje,
        array  $datos = [],
        ?int   $usuarioId = null,
    ): Notificacion {
        $n = Notificacion::create([
            'usuario_id' => $usuarioId,
            'tipo'       => $tipo,
            'titulo'     => $titulo,
            'mensaje'    => $mensaje,
            'datos'      => $datos ?: null,
        ]);

        try {
            event(new NuevaNotificacion($n));
        } catch (\Throwable) {
            // Reverb offline — notificación guardada en BD, broadcast ignorado
        }

        return $n;
    }
}
