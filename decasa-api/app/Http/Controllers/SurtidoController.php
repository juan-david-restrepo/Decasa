<?php

namespace App\Http\Controllers;

use App\Events\SurtidoAceptado;
use App\Events\SurtidoEnviado;
use App\Events\SurtidoRechazado;
use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Surtido;
use App\Models\SurtidoItem;
use App\Models\SurtidoTienda;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SurtidoController extends Controller
{
    /**
     * POST /api/inventario/surtir
     * Supervisor crea un surtido y notifica a los vendedores validadores.
     */
    public function crear(Request $request)
    {
        $data = $request->validate([
            'notas'                                     => 'nullable|string|max:1000',
            'tiendas'                                   => 'required|array|min:1',
            'tiendas.*.tienda_id'                       => 'required|exists:tiendas,id',
            'tiendas.*.vendedor_validador_id'            => 'required|exists:usuarios,id',
            'tiendas.*.items'                           => 'required|array|min:1',
            'tiendas.*.items.*.producto_id'             => 'required|exists:productos,id',
            'tiendas.*.items.*.cantidad'                => 'required|integer|min:1',
            'tiendas.*.items.*.especificaciones'        => 'nullable|array',
        ]);

        $supervisor = $request->user();

        $surtido = DB::transaction(function () use ($data, $supervisor) {
            $surtido = Surtido::create([
                'supervisor_id' => $supervisor->id,
                'notas'         => $data['notas'] ?? null,
                'estado'        => 'enviado',
            ]);

            foreach ($data['tiendas'] as $tiendaData) {
                $st = SurtidoTienda::create([
                    'surtido_id'           => $surtido->id,
                    'tienda_id'            => $tiendaData['tienda_id'],
                    'vendedor_validador_id' => $tiendaData['vendedor_validador_id'],
                    'estado'               => 'pendiente',
                ]);

                foreach ($tiendaData['items'] as $item) {
                    SurtidoItem::create([
                        'surtido_tienda_id' => $st->id,
                        'producto_id'       => $item['producto_id'],
                        'cantidad'          => $item['cantidad'],
                        'especificaciones'  => $item['especificaciones'] ?? null,
                    ]);
                }
            }

            return $surtido;
        });

        $surtido->load('tiendas.vendedorValidador:id,nombre', 'tiendas.tienda:id,nombre', 'tiendas.items.producto:id,nombre');

        // Notificar a cada vendedor validador
        foreach ($surtido->tiendas as $st) {
            $cantidadProductos = $st->items->count();

            try {
                event(new SurtidoEnviado(
                    $surtido->id,
                    $st->vendedor_validador_id,
                    $supervisor->nombre,
                    $cantidadProductos,
                ));
            } catch (\Throwable) {}

            NotificacionService::crear(
                'surtido_enviado',
                'Surtido pendiente de validación',
                "{$supervisor->nombre} envió {$cantidadProductos} producto(s) a tu tienda. Valida la recepción.",
                ['surtido_id' => $surtido->id],
                $st->vendedor_validador_id,
            );
        }

        return response()->json($surtido, 201);
    }

    /**
     * GET /api/inventario/surtidos
     * Historial de surtidos — solo supervisor.
     */
    public function index(Request $request)
    {
        $query = Surtido::with([
            'supervisor:id,nombre',
            'tiendas.tienda:id,nombre',
            'tiendas.vendedorValidador:id,nombre',
            'tiendas.items.producto:id,nombre',
        ]);

        if ($v = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $v);
        }
        if ($v = $request->query('estado')) {
            $query->where('estado', $v);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /**
     * GET /api/inventario/surtidos/pendientes
     * Surtidos pendientes de validación para el vendedor autenticado.
     */
    public function pendientes(Request $request)
    {
        $usuario = $request->user();

        $pendientes = SurtidoTienda::with([
            'surtido.supervisor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
        ])->where('vendedor_validador_id', $usuario->id)
            ->where('estado', 'pendiente')
            ->orderByDesc('id')
            ->get();

        return response()->json($pendientes);
    }

    /**
     * GET /api/inventario/surtidos/{id}
     * Detalle de un surtido.
     */
    public function show(int $id)
    {
        $surtido = Surtido::with([
            'supervisor:id,nombre',
            'tiendas.tienda:id,nombre',
            'tiendas.vendedorValidador:id,nombre',
            'tiendas.items.producto:id,nombre,categoria,foto_url',
        ])->findOrFail($id);

        return response()->json($surtido);
    }

    /**
     * PATCH /api/inventario/surtido-tiendas/{id}/aceptar
     * Vendedor acepta el surtido — actualiza inventario y movimientos.
     */
    public function aceptar(Request $request, int $id)
    {
        $usuario = $request->user();

        $st = SurtidoTienda::with(['surtido.supervisor:id,nombre', 'tienda:id,nombre', 'items'])->findOrFail($id);

        if ($st->vendedor_validador_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($st->estado !== 'pendiente') {
            return response()->json(['message' => 'Este surtido ya fue respondido.'], 422);
        }

        DB::transaction(function () use ($st, $usuario) {
            foreach ($st->items as $item) {
                $inv = Inventario::firstOrCreate(
                    ['producto_id' => $item->producto_id, 'tienda_id' => $st->tienda_id],
                    ['cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'stock_minimo' => 1]
                );
                $inv->increment('cantidad_disponible', $item->cantidad);

                InventarioMovimiento::create([
                    'producto_id' => $item->producto_id,
                    'tienda_id'   => $st->tienda_id,
                    'tipo'        => 'entrada',
                    'cantidad'    => $item->cantidad,
                    'motivo'      => 'Surtido #' . $st->surtido_id,
                    'usuario_id'  => $usuario->id,
                ]);
            }

            $st->update([
                'estado'        => 'aceptado',
                'respondido_at' => now(),
            ]);

            $this->recalcularEstadoSurtido($st->surtido_id);
        });

        $supervisor = $st->surtido->supervisor;

        try {
            event(new SurtidoAceptado(
                $st->surtido_id,
                $supervisor->id,
                $st->tienda->nombre,
                $usuario->nombre,
            ));
        } catch (\Throwable) {}

        NotificacionService::crear(
            'surtido_aceptado',
            'Surtido aceptado',
            "{$st->tienda->nombre} confirmó la recepción del surtido #{$st->surtido_id} (validado por {$usuario->nombre})",
            ['surtido_id' => $st->surtido_id],
            $supervisor->id,
        );

        return response()->json($st->fresh('tienda:id,nombre', 'vendedorValidador:id,nombre', 'items.producto:id,nombre'));
    }

    /**
     * PATCH /api/inventario/surtido-tiendas/{id}/rechazar
     * Vendedor rechaza el surtido.
     */
    public function rechazar(Request $request, int $id)
    {
        $data    = $request->validate(['notas_vendedor' => 'nullable|string|max:500']);
        $usuario = $request->user();

        $st = SurtidoTienda::with(['surtido.supervisor:id,nombre', 'tienda:id,nombre'])->findOrFail($id);

        if ($st->vendedor_validador_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($st->estado !== 'pendiente') {
            return response()->json(['message' => 'Este surtido ya fue respondido.'], 422);
        }

        $st->update([
            'estado'          => 'rechazado',
            'notas_vendedor'  => $data['notas_vendedor'] ?? null,
            'respondido_at'   => now(),
        ]);

        $this->recalcularEstadoSurtido($st->surtido_id);

        $supervisor = $st->surtido->supervisor;

        try {
            event(new SurtidoRechazado(
                $st->surtido_id,
                $supervisor->id,
                $st->tienda->nombre,
                $usuario->nombre,
                $data['notas_vendedor'] ?? null,
            ));
        } catch (\Throwable) {}

        NotificacionService::crear(
            'surtido_rechazado',
            'Surtido rechazado',
            "{$st->tienda->nombre} rechazó el surtido #{$st->surtido_id}" . ($data['notas_vendedor'] ? ": {$data['notas_vendedor']}" : ''),
            ['surtido_id' => $st->surtido_id],
            $supervisor->id,
        );

        return response()->json($st);
    }

    /**
     * GET /api/inventario/vendedores-tienda/{tiendaId}
     * Todos los vendedores activos. Los de esa tienda aparecen primero.
     * Se devuelven todos para que el supervisor pueda asignar cualquiera,
     * independientemente de si tienen tienda_default_id configurada.
     */
    public function vendedoresTienda(int $tiendaId)
    {
        $vendedores = Usuario::with('tiendaDefault:id,nombre')
            ->where('rol', 'vendedor')
            ->where('activo', true)
            ->orderByRaw('CASE WHEN tienda_default_id = ? THEN 0 ELSE 1 END', [$tiendaId])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'email', 'tienda_default_id']);

        return response()->json($vendedores);
    }

    private function recalcularEstadoSurtido(int $surtidoId): void
    {
        $tiendas    = SurtidoTienda::where('surtido_id', $surtidoId)->get();
        $pendientes = $tiendas->where('estado', 'pendiente')->count();
        $rechazados = $tiendas->where('estado', 'rechazado')->count();

        if ($pendientes === 0) {
            $nuevoEstado = $rechazados > 0 ? 'rechazado_parcial' : 'completado';
            Surtido::where('id', $surtidoId)->update(['estado' => $nuevoEstado]);
        }
    }
}
