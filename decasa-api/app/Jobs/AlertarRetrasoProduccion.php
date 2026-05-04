<?php

namespace App\Jobs;

use App\Models\Produccion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlertarRetrasoProduccion implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $hoy = now()->toDateString();

        // ── 1. Marcar como RETRASADO lo que ya venció ────────────────────────
        $vencidos = Produccion::where('estado', 'en_proceso')
            ->whereDate('fecha_compromiso', '<', $hoy)
            ->with([
                'ordenItem.producto:id,nombre',
                'ordenItem.orden.cliente:id,nombre,telefono',
                'ordenItem.orden.vendedor:id,nombre',
                'ordenItem.orden.tienda:id,nombre',
            ])
            ->get();

        foreach ($vencidos as $prod) {
            $prod->update(['estado' => 'retrasado']);

            $orden   = $prod->ordenItem->orden;
            $cliente = $orden->cliente->nombre;
            $producto = $prod->ordenItem->producto->nombre;
            $dias    = now()->diffInDays($prod->fecha_compromiso);

            Log::warning("[DECASA] Producción #{$prod->id} RETRASADA", [
                'producto'        => $producto,
                'cliente'         => $cliente,
                'telefono'        => $orden->cliente->telefono,
                'vendedor'        => $orden->vendedor->nombre,
                'tienda'          => $orden->tienda->nombre,
                'fecha_compromiso'=> $prod->fecha_compromiso,
                'dias_retraso'    => $dias,
                'orden_id'        => $orden->id,
            ]);
        }

        // ── 2. Alertar los que vencen en ≤ 3 días (sin retrasar aún) ────────
        $porVencer = Produccion::where('estado', 'en_proceso')
            ->whereRaw('DATEDIFF(fecha_compromiso, CURDATE()) BETWEEN 0 AND 3')
            ->with([
                'ordenItem.producto:id,nombre',
                'ordenItem.orden.cliente:id,nombre,telefono',
                'ordenItem.orden.vendedor:id,nombre',
                'ordenItem.orden.tienda:id,nombre',
            ])
            ->get();

        foreach ($porVencer as $prod) {
            $diasRestantes = (int) now()->startOfDay()
                ->diffInDays(\Carbon\Carbon::parse($prod->fecha_compromiso)->startOfDay(), false);

            $orden   = $prod->ordenItem->orden;
            $cliente = $orden->cliente->nombre;

            Log::info("[DECASA] Producción #{$prod->id} vence en {$diasRestantes} día(s)", [
                'producto'        => $prod->ordenItem->producto->nombre,
                'cliente'         => $cliente,
                'telefono'        => $orden->cliente->telefono,
                'vendedor'        => $orden->vendedor->nombre,
                'tienda'          => $orden->tienda->nombre,
                'fecha_compromiso'=> $prod->fecha_compromiso,
                'dias_restantes'  => $diasRestantes,
                'orden_id'        => $orden->id,
            ]);
        }

        Log::info('[DECASA] AlertarRetrasoProduccion completado', [
            'fecha'            => $hoy,
            'marcados_retrasados' => $vencidos->count(),
            'por_vencer'       => $porVencer->count(),
        ]);
    }

    /**
     * Garantiza que solo haya un job pendiente a la vez en la queue.
     */
    public function uniqueId(): string
    {
        return 'alertar-retraso-produccion';
    }
}
