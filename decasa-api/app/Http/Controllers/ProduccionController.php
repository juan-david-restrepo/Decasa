<?php

namespace App\Http\Controllers;

use App\Events\ProduccionActualizada;
use App\Models\Produccion;
use App\Services\NotificacionService;
use Illuminate\Http\Request;

class ProduccionController extends Controller
{
    /**
     * GET /api/produccion?estado=en_proceso&tienda_id=1&page=1
     *
     * Lista pedidos personalizados en producción con paginación.
     * Incluye días restantes (negativo = retraso).
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Produccion::with([
            'ordenItem.producto:id,nombre,categoria,foto_url',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.vendedor:id,nombre',
            'ordenItem.orden.tienda:id,nombre',
        ]);

        // Vendedor solo ve sus propios pedidos de producción
        if ($usuario->rol === 'vendedor') {
            $query->whereHas('ordenItem.orden', fn($q) => $q->where('vendedor_id', $usuario->id));
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($tiendaId = $request->query('tienda_id')) {
            $query->whereHas('ordenItem.orden', fn($q) => $q->where('tienda_id', $tiendaId));
        }

        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->whereHas('ordenItem.producto', fn($p) => $p->where('nombre', 'like', $term))
                  ->orWhereHas('ordenItem.orden.cliente', fn($c) => $c->where('nombre', 'like', $term));
            });
        }

        $producciones = $query
            ->orderByRaw("FIELD(estado, 'pendiente', 'retrasado', 'en_proceso', 'listo', 'entregado')")
            ->orderBy('fecha_compromiso')
            ->paginate(20)
            ->through(function ($p) {
                $hoy = now()->startOfDay();
                $compromiso = \Carbon\Carbon::parse($p->fecha_compromiso)->startOfDay();
                $p->dias_restantes = $hoy->diffInDays($compromiso, false);
                return $p;
            });

        return response()->json($producciones);
    }

    /**
     * PATCH /api/produccion/{id}
     *
     * Actualiza estado del pedido de producción.
     * Reglas:
     *   - 'retrasado'  → motivo_retraso obligatorio
     *   - 'listo' o 'entregado' → registra fecha_real = hoy
     */
    public function update(Request $request, int $id)
    {
        $usuario    = $request->user();
        $produccion = Produccion::with('ordenItem.orden')->findOrFail($id);

        // Vendedor solo puede actualizar sus propios pedidos
        if ($usuario->rol === 'vendedor' &&
            $produccion->ordenItem->orden->vendedor_id !== $usuario->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $data = $request->validate([
            'estado'         => 'required|in:pendiente,en_proceso,listo,retrasado,entregado',
            'motivo_retraso' => 'nullable|string|max:500',
        ]);

        if ($data['estado'] === 'retrasado' && empty($data['motivo_retraso'])) {
            return response()->json([
                'message' => 'Se requiere motivo_retraso cuando el estado es retrasado.',
                'errors'  => ['motivo_retraso' => ['Campo obligatorio para estado retrasado.']],
            ], 422);
        }

        $updates = ['estado' => $data['estado']];

        if (! empty($data['motivo_retraso'])) {
            $updates['motivo_retraso'] = $data['motivo_retraso'];
        }

        if (in_array($data['estado'], ['listo', 'entregado'])) {
            $updates['fecha_real'] = now()->toDateString();
        }

        $produccion->update($updates);

        event(new ProduccionActualizada(
            $produccion->id,
            $produccion->ordenItem->orden->id,
            $data['estado'],
        ));

        // Load relations needed for notification and response
        $produccion->load([
            'ordenItem.producto:id,nombre,categoria',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.tienda:id,nombre',
        ]);

        $productoNombre = $produccion->ordenItem->producto->nombre;
        $clienteNombre  = $produccion->ordenItem->orden->cliente->nombre;
        $tiendaNombre   = $produccion->ordenItem->orden->tienda->nombre;
        $ordenId        = $produccion->ordenItem->orden->id;

        $vendedorId = $produccion->ordenItem->orden->vendedor_id;

        if ($data['estado'] === 'retrasado') {
            NotificacionService::crear(
                'retrasado',
                'Producción retrasada',
                "{$productoNombre} — {$clienteNombre} ({$tiendaNombre})",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
            );
            NotificacionService::crear(
                'retrasado',
                'Tu pedido está atrasado',
                "{$productoNombre} para {$clienteNombre} ha superado el tiempo comprometido",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
                $vendedorId,
            );
        } elseif ($data['estado'] === 'entregado') {
            NotificacionService::crear(
                'entregado',
                'Producto entregado',
                "Orden #{$ordenId} — {$productoNombre} · {$clienteNombre}",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
            );
            NotificacionService::crear(
                'entregado',
                'Tu pedido fue entregado',
                "{$productoNombre} para {$clienteNombre} ha sido entregado",
                ['produccion_id' => $produccion->id, 'orden_id' => $ordenId],
                $vendedorId,
            );
        }

        // Recalcular dias_restantes en la respuesta
        $hoy        = now()->startOfDay();
        $compromiso = \Carbon\Carbon::parse($produccion->fecha_compromiso)->startOfDay();
        $produccion->dias_restantes = $hoy->diffInDays($compromiso, false);

        return response()->json($produccion);
    }
}
