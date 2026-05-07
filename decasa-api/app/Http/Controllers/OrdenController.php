<?php

namespace App\Http\Controllers;

use App\Events\InventarioActualizado;
use App\Events\OrdenActualizada;
use App\Mail\CotizacionMail;
use App\Services\NotificacionService;
use App\Models\Inventario;
use App\Models\Usuario;
use App\Models\InventarioMovimiento;
use App\Models\InventarioVariante;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProductoVariante;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->whereHas('cliente', fn($q) => $q->where('nombre', 'like', $term));
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
            'notas'                              => 'nullable|string|max:1000',
            'factura_foto_url'                   => 'nullable|string|max:500',
            'firma_url'                          => 'nullable|string|max:500',
            'direccion_envio'                    => 'nullable|string|max:300',
            'ciudad_envio'                       => 'nullable|string|max:100',
            'anticipo_monto'                     => 'required|numeric|min:1',
            'anticipo_metodo'                    => 'required|in:efectivo,transferencia,tarjeta,otro',
            'anticipo_referencia'                => 'nullable|string|max:100',
            'items'                              => 'required|array|min:1',
            'items.*.producto_id'                => 'required|exists:productos,id',
            'items.*.variante_id'                => 'nullable|exists:producto_variantes,id',
            'items.*.tienda_origen_id'           => 'nullable|exists:tiendas,id',
            'items.*.cantidad'                   => 'required|integer|min:1',
            'items.*.precio_unitario'            => 'required|numeric|min:0',
            'items.*.es_personalizado'           => 'nullable|boolean',
            'items.*.specs_personalizacion'      => 'nullable|array',
            'items.*.boceto_url'                 => 'nullable|string|max:500',
            'items.*.fecha_entrega_prometida'    => 'nullable|date',
        ]);

        // Vendedor debe tener firma registrada antes de crear órdenes
        if ($request->user()->rol === 'vendedor' && ! $request->user()->firma_url) {
            return response()->json([
                'message' => 'Debes registrar tu firma en Mi Perfil antes de crear órdenes.',
            ], 422);
        }

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

        // Protección contra doble envío: misma orden del mismo vendedor en los últimos 15 segundos
        $duplicado = Orden::where('vendedor_id', $request->user()->id)
            ->where('cliente_id', $data['cliente_id'])
            ->where('tienda_id', $tiendaId)
            ->where('valor_total', $valorTotal)
            ->where('created_at', '>=', now()->subSeconds(15))
            ->first();

        if ($duplicado) {
            return response()->json([
                'message' => 'Esta orden ya fue registrada hace unos segundos.',
                'orden_id' => $duplicado->id,
            ], 409);
        }

        $orden = DB::transaction(function () use ($data, $tiendaId, $anticupoPct, $valorTotal, $request) {

            // --- 1. Verificar stock para items no personalizados (con bloqueo) ---
            foreach ($data['items'] as $item) {
                if (! ($item['es_personalizado'] ?? false)) {
                    $varianteId    = $item['variante_id']      ?? null;
                    $origenTiendaId = $item['tienda_origen_id'] ?? $tiendaId;

                    if ($varianteId) {
                        $inv = InventarioVariante::where('variante_id', $varianteId)
                            ->where('tienda_id', $origenTiendaId)
                            ->lockForUpdate()->first();
                    } else {
                        $inv = Inventario::where('producto_id', $item['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->lockForUpdate()->first();
                    }

                    $stockLibre = $inv
                        ? $inv->cantidad_disponible - $inv->cantidad_reservada
                        : 0;

                    if ($stockLibre < $item['cantidad']) {
                        $where = $varianteId ? "variante ID {$varianteId}" : "producto ID {$item['producto_id']}";
                        abort(422, "Stock insuficiente para {$where}. Stock libre: {$stockLibre}, solicitado: {$item['cantidad']}.");
                    }
                }
            }

            // --- 2. Crear la orden ---
            $orden = Orden::create([
                'cliente_id'        => $data['cliente_id'],
                'vendedor_id'       => $request->user()->id,
                'tienda_id'         => $tiendaId,
                'canal'             => $data['canal'],
                'estado'            => 'pendiente_anticipo',
                'valor_total'       => $valorTotal,
                'anticipo_pct'      => $anticupoPct,
                'notas'             => $data['notas'] ?? null,
                'factura_foto_url'  => $data['factura_foto_url'] ?? null,
                'firma_url'         => $data['firma_url'] ?? null,
                'direccion_envio'   => $data['direccion_envio'] ?? null,
                'ciudad_envio'      => $data['ciudad_envio'] ?? null,
            ]);

            // --- 3. Crear items, reservar stock y crear producción ---
            foreach ($data['items'] as $itemData) {
                $esPersonalizado = (bool) ($itemData['es_personalizado'] ?? false);

                $varianteId     = $itemData['variante_id']      ?? null;
                $origenTiendaId = $itemData['tienda_origen_id'] ?? $tiendaId;

                // Snapshot del nombre de variante para legibilidad
                $specsExtra = $itemData['specs_personalizacion'] ?? null;
                if ($varianteId && ! $esPersonalizado) {
                    $v = ProductoVariante::find($varianteId);
                    $specsExtra = array_merge($specsExtra ?? [], [
                        'variante_marca' => $v?->marca_tela,
                        'variante_color' => $v?->nombre_color,
                    ]);
                }

                $item = OrdenItem::create([
                    'orden_id'              => $orden->id,
                    'producto_id'           => $itemData['producto_id'],
                    'variante_id'           => $varianteId,
                    'tienda_origen_id'      => $origenTiendaId !== $tiendaId ? $origenTiendaId : null,
                    'cantidad'              => $itemData['cantidad'],
                    'precio_unitario'       => $itemData['precio_unitario'],
                    'es_personalizado'      => $esPersonalizado,
                    'specs_personalizacion' => $specsExtra,
                    'boceto_url'            => $itemData['boceto_url'] ?? null,
                    'fecha_entrega_prom'    => $itemData['fecha_entrega_prometida'] ?? null,
                ]);

                if ($esPersonalizado) {
                    Produccion::create([
                        'orden_item_id'    => $item->id,
                        'fecha_inicio'     => now()->toDateString(),
                        'fecha_compromiso' => $itemData['fecha_entrega_prometida'] ?? null,
                        'estado'           => 'pendiente',
                    ]);
                } else {
                    // Reservar stock en la tienda de origen (puede ser otra tienda)
                    $motivo = "Orden #{$orden->id}" . ($varianteId ? " ({$specsExtra['variante_marca']} - {$specsExtra['variante_color']})" : '');

                    if ($varianteId) {
                        InventarioVariante::where('variante_id', $varianteId)
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                        // Las variantes son parte del stock base → reservar en ambos
                        Inventario::where('producto_id', $itemData['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                    } else {
                        Inventario::where('producto_id', $itemData['producto_id'])
                            ->where('tienda_id', $origenTiendaId)
                            ->increment('cantidad_reservada', $itemData['cantidad']);
                    }

                    InventarioMovimiento::create([
                        'producto_id' => $itemData['producto_id'],
                        'tienda_id'   => $origenTiendaId,
                        'tipo'        => 'reserva',
                        'cantidad'    => $itemData['cantidad'],
                        'motivo'      => $motivo,
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

        $ordenCargada = $orden->load([
            'cliente:id,nombre,cedula,telefono',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.producto:id,nombre,categoria,foto_url',
            'items.produccion',
            'pagos',
        ]);

        event(new OrdenActualizada(
            $orden->id,
            (int) $tiendaId,
            'pendiente_anticipo',
            $ordenCargada->cliente->nombre,
        ));

        NotificacionService::crear(
            'venta_nueva',
            'Nueva venta registrada',
            "Orden #{$orden->id} — {$ordenCargada->cliente->nombre} · $" . number_format($valorTotal, 0, ',', '.') . " COP",
            ['orden_id' => $orden->id, 'tienda_id' => (int) $tiendaId, 'valor_total' => $valorTotal],
        );

        // Notificar al supervisor que debe asignar fecha de entrega
        NotificacionService::crear(
            'asignar_fecha',
            'Asignar fecha de entrega',
            "Orden #{$orden->id} de {$ordenCargada->cliente->nombre} necesita fecha de entrega",
            ['orden_id' => $orden->id],
            // usuario_id null → va al supervisor
        );

        // Notificar cambio de inventario y detectar ventas cruzadas
        $origenesExternos = [];
        foreach ($data['items'] as $itemData) {
            if (! ($itemData['es_personalizado'] ?? false)) {
                $origenTiendaId = $itemData['tienda_origen_id'] ?? $tiendaId;
                event(new InventarioActualizado((int) $origenTiendaId, (int) $itemData['producto_id'], 'reserva'));

                if ($origenTiendaId && (int) $origenTiendaId !== (int) $tiendaId) {
                    $origenesExternos[] = (int) $origenTiendaId;
                }
            }
        }

        // Notificar a vendedores de la tienda fuente cuando se usa su stock
        foreach (array_unique($origenesExternos) as $origenId) {
            $vendedoresOrigen = Usuario::where('tienda_default_id', $origenId)
                ->where('rol', 'vendedor')
                ->where('activo', true)
                ->pluck('id');

            foreach ($vendedoresOrigen as $vendedorId) {
                NotificacionService::crear(
                    'venta_otra_tienda',
                    'Venta desde otra tienda',
                    "Orden #{$orden->id} de {$ordenCargada->cliente->nombre} usó stock de tu tienda",
                    ['orden_id' => $orden->id, 'tienda_id' => $origenId],
                    $vendedorId,
                );
            }
        }

        // Enviar cotización por email si el cliente tiene email
        if ($ordenCargada->cliente->email) {
            try {
                Mail::to($ordenCargada->cliente->email)
                    ->send(new CotizacionMail($orden->id));
            } catch (\Throwable) {
                // El email nunca bloquea la respuesta
            }
        }

        return response()->json($ordenCargada, 201);
    }

    /**
     * POST /api/ordenes/{id}/reenviar-cotizacion
     * Re-envía la cotización por email al cliente.
     */
    public function reenviarCotizacion(Request $request, int $id)
    {
        $usuario = $request->user();

        $orden = Orden::with('cliente:id,nombre,email')->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $email = $request->input('email') ?? $orden->cliente->email;

        if (! $email) {
            return response()->json(['message' => 'El cliente no tiene email registrado.'], 422);
        }

        Mail::to($email)->send(new CotizacionMail($orden->id));

        return response()->json(['message' => "Cotización enviada a {$email}."]);
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

        if ($usuario->rol === 'vendedor') {
            return response()->json(['message' => 'Solo el supervisor puede cambiar el estado de las órdenes.'], 403);
        }

        $orden = Orden::with('items')->findOrFail($id);

        $estadoAnterior = $orden->estado;
        $estadoNuevo    = $data['estado'];

        if ($estadoAnterior === $estadoNuevo) {
            return response()->json($orden, 200);
        }

        DB::transaction(function () use ($orden, $estadoNuevo, $estadoAnterior, $usuario) {

            $itemsStock = $orden->items->where('es_personalizado', false);

            foreach ($itemsStock as $item) {
                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;

                if ($estadoNuevo === 'entregado') {
                    if ($item->variante_id) {
                        InventarioVariante::where('variante_id', $item->variante_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                            ]);
                        // Variante es parte del stock base → descontar en ambos
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                            ]);
                    } else {
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->update([
                                'cantidad_disponible' => DB::raw("cantidad_disponible - {$item->cantidad}"),
                                'cantidad_reservada'  => DB::raw("cantidad_reservada - {$item->cantidad}"),
                            ]);
                    }
                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $origenId,
                        'tipo'        => 'salida',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Entrega orden #{$orden->id}",
                        'usuario_id'  => $usuario->id,
                    ]);
                } elseif ($estadoNuevo === 'cancelado' && $estadoAnterior !== 'cancelado') {
                    if ($item->variante_id) {
                        InventarioVariante::where('variante_id', $item->variante_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', $item->cantidad);
                        // Liberar también en el stock base
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', $item->cantidad);
                    } else {
                        Inventario::where('producto_id', $item->producto_id)
                            ->where('tienda_id', $origenId)
                            ->decrement('cantidad_reservada', $item->cantidad);
                    }
                    InventarioMovimiento::create([
                        'producto_id' => $item->producto_id,
                        'tienda_id'   => $origenId,
                        'tipo'        => 'liberacion',
                        'cantidad'    => $item->cantidad,
                        'motivo'      => "Cancelación orden #{$orden->id}",
                        'usuario_id'  => $usuario->id,
                    ]);
                }
            }

            $orden->update(['estado' => $estadoNuevo]);
        });

        $ordenFresh = $orden->fresh(['items.producto:id,nombre', 'items.produccion', 'pagos', 'cliente:id,nombre', 'tienda:id,nombre']);

        event(new OrdenActualizada(
            $orden->id,
            (int) $orden->tienda_id,
            $estadoNuevo,
            $ordenFresh->cliente->nombre,
        ));

        if ($estadoNuevo === 'entregado') {
            // Supervisor
            NotificacionService::crear(
                'entregado',
                'Orden entregada',
                "Orden #{$orden->id} entregada a {$ordenFresh->cliente->nombre}",
                ['orden_id' => $orden->id, 'tienda_id' => (int) $orden->tienda_id],
            );
            // Vendedor
            NotificacionService::crear(
                'entregado',
                'Tu orden fue entregada',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} recibió su pedido",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'en_produccion') {
            NotificacionService::crear(
                'en_produccion',
                'Tu pedido entró en producción',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} está en producción",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        if ($estadoNuevo === 'cancelado') {
            NotificacionService::crear(
                'cancelado',
                'Tu orden fue cancelada',
                "Orden #{$orden->id} — {$ordenFresh->cliente->nombre} fue cancelada",
                ['orden_id' => $orden->id],
                $orden->vendedor_id,
            );
        }

        // Notificar inventario cuando se entrega o cancela
        if (in_array($estadoNuevo, ['entregado', 'cancelado'])) {
            foreach ($orden->items->where('es_personalizado', false) as $item) {
                $origenId = $item->tienda_origen_id ?? $orden->tienda_id;
                $tipo = $estadoNuevo === 'entregado' ? 'salida' : 'liberacion';
                event(new InventarioActualizado((int) $origenId, (int) $item->producto_id, $tipo));
            }
        }

        return response()->json($ordenFresh);
    }

    /**
     * PATCH /api/ordenes/{id}/fechas-entrega
     * Solo supervisor. Asigna fecha de entrega a cada ítem y notifica al vendedor.
     */
    public function asignarFechas(Request $request, int $id)
    {
        $data = $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.id'    => 'required|integer|exists:orden_items,id',
            'items.*.fecha' => 'required|date',
        ]);

        $orden = Orden::with(['items', 'cliente:id,nombre', 'vendedor:id,nombre'])->findOrFail($id);

        DB::transaction(function () use ($data, $orden) {
            foreach ($data['items'] as $itemData) {
                $orden->items()
                    ->where('id', $itemData['id'])
                    ->update(['fecha_entrega_prom' => $itemData['fecha']]);

                // Sincronizar fecha_compromiso en producción si existe
                $item = $orden->items->firstWhere('id', $itemData['id']);
                if ($item) {
                    \App\Models\Produccion::where('orden_item_id', $item->id)
                        ->update(['fecha_compromiso' => $itemData['fecha']]);
                }
            }
        });

        // Notificar al vendedor que ya tiene fecha de entrega
        NotificacionService::crear(
            'fecha_asignada',
            'Fecha de entrega asignada',
            "La orden #{$orden->id} de {$orden->cliente->nombre} ya tiene fecha de entrega",
            ['orden_id' => $orden->id],
            $orden->vendedor_id,
        );

        return response()->json(['message' => 'Fechas asignadas correctamente.']);
    }

    /**
     * GET /api/ordenes/{id}/pdf
     */
    public function pdf(Request $request, int $id)
    {
        $usuario = $request->user();

        $orden = Orden::with([
            'cliente',
            'tienda:id,nombre',
            'vendedor:id,nombre,firma_url',
            'items.producto:id,nombre,categoria',
            'pagos',
        ])->findOrFail($id);

        if ($usuario->rol === 'vendedor' && $orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $orden->total_pagado    = $orden->totalPagado();
        $orden->saldo_pendiente = $orden->saldoPendiente();
        $orden->porcentaje_pagado = $orden->valor_total > 0
            ? min(100, round(($orden->total_pagado / $orden->valor_total) * 100))
            : 0;

        // Convertir firmas a base64 para renderizado confiable en DomPDF
        $firmaCliente = $this->urlToBase64($orden->firma_url);
        $firmaVendedor = $this->urlToBase64($orden->vendedor?->firma_url);

        // Logo: leer AVIF y convertir a PNG base64 para DomPDF
        $logoBase64 = $this->avifToPngBase64(public_path('img/logo.avif'));

        // Bocetos de ítems personalizados: convertir URLs a base64
        $bocetosBase64 = [];
        foreach ($orden->items as $item) {
            if ($item->es_personalizado && $item->boceto_url) {
                $bocetosBase64[$item->id] = $this->urlToBase64($item->boceto_url);
            }
        }

        $pdf = Pdf::loadView('pdf.orden', compact('orden', 'firmaCliente', 'firmaVendedor', 'logoBase64', 'bocetosBase64'));
        $pdf->setPaper('letter');

        return $pdf->download('orden-' . $orden->id . '.pdf');
    }

    private function urlToBase64(?string $url): ?string
    {
        if (! $url) return null;
        try {
            $bytes = file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
                'ssl'  => ['verify_peer' => false],
            ]));
            return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function avifToPngBase64(string $path): ?string
    {
        if (! file_exists($path)) return null;
        try {
            $img = imagecreatefromavif($path);
            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return 'data:image/png;base64,' . base64_encode($data);
        } catch (\Throwable) {
            return null;
        }
    }
}
