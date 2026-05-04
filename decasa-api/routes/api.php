<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\OrdenController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\ProduccionController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\TiendaController;
use Illuminate\Support\Facades\Route;

// ── Auth (público) ────────────────────────────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login']);

// ── Rutas protegidas ─────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Tiendas (solo lectura — usada por el selector de tienda en la orden)
    Route::get('/tiendas', [TiendaController::class, 'index']);

    // Productos
    Route::get('/productos',      [ProductoController::class, 'index']);
    Route::get('/productos/{id}', [ProductoController::class, 'show']);
    Route::patch('/productos/{id}', [ProductoController::class, 'update']);

    // Clientes
    Route::get('/clientes',              [ClienteController::class, 'index']);
    Route::post('/clientes',             [ClienteController::class, 'store']);
    Route::get('/clientes/{id}',         [ClienteController::class, 'show']);
    Route::get('/clientes/{id}/ordenes', [ClienteController::class, 'ordenes']);

    // Órdenes
    Route::get('/ordenes',              [OrdenController::class, 'index']);
    Route::post('/ordenes',             [OrdenController::class, 'store']);
    Route::get('/ordenes/{id}',         [OrdenController::class, 'show']);
    Route::patch('/ordenes/{id}/estado', [OrdenController::class, 'updateEstado']);

    // Pagos
    Route::get('/ordenes/{id}/pagos',  [PagoController::class, 'index']);
    Route::post('/ordenes/{id}/pagos', [PagoController::class, 'store']);

    // Inventario
    Route::get('/inventario',          [InventarioController::class, 'index']);
    Route::post('/inventario/entrada', [InventarioController::class, 'entrada']);

    // Producción
    Route::get('/produccion',        [ProduccionController::class, 'index']);
    Route::patch('/produccion/{id}', [ProduccionController::class, 'update']);

    // Reportes (solo supervisor)
    Route::middleware('role:supervisor')->prefix('reportes')->group(function () {
        Route::get('/ventas',         [ReporteController::class, 'ventas']);
        Route::get('/vendedores',     [ReporteController::class, 'vendedores']);
        Route::get('/productos-top',  [ReporteController::class, 'productosTop']);
        Route::get('/pendientes',     [ReporteController::class, 'pendientes']);
        Route::get('/retrasos',       [ReporteController::class, 'retrasos']);
        Route::get('/exportar',       [ReporteController::class, 'exportar']);
    });
});
