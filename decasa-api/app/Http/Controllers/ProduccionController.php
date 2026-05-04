<?php

namespace App\Http\Controllers;

use App\Models\Produccion;
use Illuminate\Http\Request;

class ProduccionController extends Controller
{
    /**
     * GET /api/produccion?estado=en_proceso&tienda_id=1
     *
     * Lista pedidos personalizados en producción.
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

        $producciones = $query
            ->orderBy('fecha_compromiso')
            ->get()
            ->map(function ($p) {
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
            'estado'         => 'required|in:en_proceso,listo,retrasado,entregado',
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

        // Recalcular dias_restantes en la respuesta
        $hoy        = now()->startOfDay();
        $compromiso = \Carbon\Carbon::parse($produccion->fecha_compromiso)->startOfDay();
        $produccion->dias_restantes = $hoy->diffInDays($compromiso, false);

        return response()->json($produccion->load([
            'ordenItem.producto:id,nombre,categoria',
            'ordenItem.orden.cliente:id,nombre,telefono',
            'ordenItem.orden.tienda:id,nombre',
        ]));
    }
}
