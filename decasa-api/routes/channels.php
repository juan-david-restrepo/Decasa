<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canales públicos — toda la empresa comparte los mismos
Broadcast::channel('ordenes',         fn () => true);
Broadcast::channel('inventario',      fn () => true);
Broadcast::channel('produccion',      fn () => true);
Broadcast::channel('notificaciones',          fn () => true);
Broadcast::channel('notificaciones.{userId}', fn () => true);
