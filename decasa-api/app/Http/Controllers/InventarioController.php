<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\InventarioMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    /**
     * GET /api/inventario?tienda_id=1&search=sofa
     *
     * Devuelve inventario de una tienda con info del producto.
     * Añade flag `bajo_stock` cuando disponible <= stock_minimo.
     */
    public function index(Request $request)
    {
        $request->validate([
            'tienda_id' => 'required|exists:tiendas,id',
        ]);

        $tiendaId = $request->query('tienda_id');

        $query = Inventario::with('producto:id,nombre,categoria,precio_base,foto_url,personalizable,activo')
            ->where('tienda_id', $tiendaId)
            ->whereHas('producto', fn($q) => $q->where('activo', true));

        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->whereHas('producto', function ($q) use ($term) {
                $q->where('nombre', 'like', $term)
                  ->orWhere('categoria', 'like', $term);
            });
        }

        $inventario = $query
            ->orderBy(
                DB::raw("(SELECT nombre FROM productos WHERE productos.id = inventario.producto_id)")
            )
            ->get()
            ->map(function ($inv) {
                $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
                $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;
                return $inv;
            });

        return response()->json($inventario);
    }

    /**
     * POST /api/inventario/entrada
     * Solo supervisor puede agregar stock.
     */
    public function entrada(Request $request)
    {
        // Tanto vendedores como supervisores pueden agregar stock

        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tienda_id'   => 'required|exists:tiendas,id',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        DB::transaction(function () use ($data, $request) {
            Inventario::where('producto_id', $data['producto_id'])
                ->where('tienda_id', $data['tienda_id'])
                ->increment('cantidad_disponible', $data['cantidad']);

            InventarioMovimiento::create([
                'producto_id' => $data['producto_id'],
                'tienda_id'   => $data['tienda_id'],
                'tipo'        => 'entrada',
                'cantidad'    => $data['cantidad'],
                'motivo'      => $data['motivo'] ?? 'Entrada manual',
                'usuario_id'  => $request->user()->id,
            ]);
        });

        $inv = Inventario::with('producto:id,nombre,categoria')
            ->where('producto_id', $data['producto_id'])
            ->where('tienda_id', $data['tienda_id'])
            ->first();

        $inv->stock_libre = $inv->cantidad_disponible - $inv->cantidad_reservada;
        $inv->bajo_stock  = $inv->cantidad_disponible <= $inv->stock_minimo;

        return response()->json($inv, 201);
    }
}
