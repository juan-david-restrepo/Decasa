<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdenController extends Controller
{
    /**
     * GET /api/ordenes
     * Vendedor: solo las suyas. Supervisor: todas.
     * Filtros: estado, tienda_id, desde, hasta.
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Orden::with([
            'cliente:id,nombre,telefono',
            'tienda:id,nombre',
            'vendedor:id,nombre',
        ])->withSum('pagos', 'monto');

        if ($usuario->rol === 'vendedor') {
            $query->where('vendedor_id', $usuario->id);
        }

        if ($v = $request->query('estado')) {
            $query->where('estado', $v);
        }
        if ($v = $request->query('tienda_id')) {
            $query->where('tienda_id', $v);
        }
        if ($v = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $v);
        }
        if ($v = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $v);
        }

        $ordenes = $query->orderByDesc('created_at')->paginate(20);

        $ordenes->getCollection()->transform(function ($o) {
            $o->total_pagado    = (float) ($o->pagos_sum_monto ?? 0);
            $o->saldo_pendiente = (float) $o->valor_total - $o->total_pagado;
            return $o;
        });

        return response()->json($ordenes);
    }

    /**
     * POST /api/ordenes
     *
     * Crea la orden, reserva inventario, registra anticipo y
     * crea registros de producción para items personalizados.
     * Todo en una sola transacción atómica.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'                    => 'required|exists:clientes,id',
            'tienda_id'                     => 'required|exists:tiendas,id',
            'canal'                         => 'required|in:fisica,whatsapp,red_social,otro',
            'anticipo_pct'                  => 'nullable|numeric|min:1|max:100',
            'notas'                         => 'nullable|string|max:1000',
            'anticipo_monto'                => 'required|numeric|min:1',
            'anticipo_metodo'               => 'required|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'           => 'nullable|string|max:100',
            'items'                         => 'required|array|min:1',
            'items.*.producto_id'           => 'required|exists:productos,id',
            'items.*.cantidad'              => 'required|integer|min:1',
            'items.*.precio_unitario'       => 'required|numeric|min:0',
            'items.*.es_personalizado'      => 'nullable|boolean',
            'items.*.specs_personalizacion' => 'nullable|array',
        ]);

        $tiendaId   = $data['tienda_id'];
        $anticupoPct = $data['anticipo_pct'] ?? 50;

        // Calcular valor total server-side
        $valorTotal = collect($data['items'])->sum(
            fn ($i) => $i['cantidad'] * $i['precio_unitario']
        );

        // Validar que el anticipo cubra el porcentaje mínimo
        $minimoAnticipo = round($valorTotal * $anticupoPct / 100, 2);
        if ($data['anticipo_monto'] < $minimoAnticipo) {
            return response()->json([
                'message' => "El anticipo mínimo es " . number_format($minimoAnticipo, 2) . " COP ({$anticupoPct}% de " . number_format($valorTotal, 2) . ").",
                'errors'  => ['anticipo_monto' => ["Debe ser al menos {$minimoAnticipo}"]],
            ], 422);
        }

        $orden = DB::transaction(function () use ($data, $tiendaId, $anticupoPct, $valorTotal, $request) {

            // --- 1. Verificar stock para items no personalizados (con bloqueo) ---
            foreach ($data['items'] as $item) {
                if (! ($item['es_personalizado'] ?? false)) {
                    $inv = Inventario::where('producto_id', $item['producto_id'])
                        ->where('tienda_id', $tiendaId)
                        ->lockForUpdate()
                        ->first();

                    $stockLibre = $inv
                        ? $inv->cantidad_disponible - $inv->cantidad_reservada
                        : 0;

                    if ($stockLibre < $item['cantidad']) {
                        abort(422, "Stock insuficiente para producto ID {$item['producto_id']}. Stock libre: {$stockLibre}, solicitado: {$item['cantidad']}.");
                    }
                }
            }

            // --- 2. Crear la orden ---
            $orden = Orden::create([
                'cliente_id'   => $data['cliente_id'],
                'vendedor_id'  => $request->user()->id,
                'tienda_id'    => $tiendaId,
                'canal'        => $data['canal'],
                'estado'       => 'pendiente_anticipo',
                'valor_total'  => $valorTotal,
                'anticipo_pct' => $anticupoPct,
                'notas'        => $data['notas'] ?? null,
            ]);

            // --- 3. Crear items, reservar stock y crear producción ---
            foreach ($data['items'] as $itemData) {
                $esPersonalizado = (bool) ($itemData['es_personalizado'] ?? false);

                $item = OrdenItem::create([
                    'orden_id'              => $orden->id,
                    'producto_id'           => $itemData['producto_id'],
                    'cantidad'              => $itemData['cantidad'],
                    'precio_unitario'       => $itemData['precio_unitario'],
                    'es_personalizado'      => $esPersonalizado,
                    'specs_personalizacion' => $itemData['specs_personalizacion'] ?? null,
                    'fecha_entrega_prom'    => $esPersonalizado
                        ? now()->addDays(30)->toDateString()
                        : null,
                ]);

                if ($esPersonalizado) {
                    // Producción automática: 30 días de plazo
                    Produccion::create([
                        'orden_item_id'    => $item->id,
                        'fecha_inicio'     => now()->toDateString(),
                        'fecha_compromiso' => now()->addDays(30)->toDateString(),
                        'estado'           => 'en_proceso',
                    ]);
                } else {
                    // Reservar stock
                    Inventario::where('producto_id', $itemData['producto_id'])
                        ->where('tienda_id', $tiendaId)
                        ->increment('cantidad_reservada', $itemData['cantidad']);

                    InventarioMovimiento::create([
                        'producto_id' => $itemData['producto_id'],
                        'tienda_id'   => $tiendaId,
                        'tipo'        => 'reserva',
                        'cantidad'    => $itemData['cantidad'],
                        'motivo'      => "Orden #{$orden->id}",
                        'usuario_id'  => $request->user()->id,
                    ]);
                }
            }

            // --- 4. Registrar anticipo ---
            $orden->pagos()->create([
                'vendedor_id' => $request->user()->id,
                'tipo'        => 'anticipo',
                'monto'       => $data['anticipo_monto'],
                'metodo'      => $data['anticipo_metodo'],
                'referencia'  => $data['anticipo_referencia'] ?? null,
            ]);

            return $orden;
        });

        return response()->json(
            $orden->load([
                'cliente:id,nombre,cedula,telefono',
                'vendedor:id,nombre',
                'tienda:id,nombre',
                'items.producto:id,nombre,categoria,foto_url',
                'items.produccion',
                'pagos',
            ]),
            201
        );
    }

    /**
     * GET /api/ordenes/{id}
     */
    public function show(Request $request, int $id)
    {
        $usuario = $request->user();

        $orden = Orden::with([
            'cliente',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,precio_base,personalizable,foto_url,medidas,material',
            'items.produccion',
            'pagos',
        ])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $orden->total_pagado    = $orden->totalPagado();
        $orden->saldo_pendiente = $orden->saldoPendiente();

        return response()->json($orden);
    }

    /**
     * PATCH /api/ordenes/{id}/estado
     *
     * Transiciones que afectan inventario:
     *   → entregado : descuenta cantidad_disponible y libera cantidad_reservada
     *   → cancelado : solo libera cantidad_reservada
     */
    public function updateEstado(Request $request, int $id)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'estado' => 'required|in:pendiente_anticipo,en_produccion,listo_entrega,entregado,cancelado',
        ]);

        $orden = Orden::with('items')->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $estadoAnterior = $orden->estado;
        $estadoNuevo    = $data['estado'];

        if ($estadoAnterior === $estadoNuevo) {
            return response()->json($orden, 200);
        }

        DB::transaction(function () use ($orden, $estadoNuevo, $estadoAnterior, $usuario) {

            $itemsStock = $orden->items->where('es_personalizado', false);

            if ($estadoNuevo === 'entregado') {
                // Confirmar salida física: restar disponible Y liberar reserva
                foreach ($itemsStock as $item) {
                    Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $orden->tienda_id)
                        ->update([
                            'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                            'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                        ]);

                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $orden->tienda_id,
                        'tipo'        => 'salida',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Entrega orden #{$orden->id}",
                        'usuario_id'  => $usuario->id,
                    ]);
                }
            } elseif ($estadoNuevo === 'cancelado' && $estadoAnterior !== 'cancelado') {
                // Cancelación: solo liberar la reserva, el stock físico no cambia
                foreach ($itemsStock as $item) {
                    Inventario::where('producto_id', $item->producto_id)
                        ->where('tienda_id', $orden->tienda_id)
                        ->decrement('cantidad_reservada', $item->cantidad);

                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $orden->tienda_id,
                        'tipo'        => 'liberacion',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Cancelación orden #{$orden->id}",
                        'usuario_id'  => $usuario->id,
                    ]);
                }
            }

            $orden->update(['estado' => $estadoNuevo]);
        });

        return response()->json(
            $orden->fresh(['items.producto:id,nombre', 'items.produccion', 'pagos', 'cliente:id,nombre', 'tienda:id,nombre'])
        );
    }
}
