<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Producto;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    /**
     * GET /api/productos?search=silla&tienda_id=1
     *
     * Devuelve productos activos. Si se pasa tienda_id, incluye
     * el stock de esa tienda en cada producto (sin N+1).
     */
    public function index(Request $request)
    {
        $query = Producto::where('activo', true);

        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('nombre',    'like', $term)
                  ->orWhere('categoria', 'like', $term);
            });
        }

        $productos = $query->orderBy('categoria')->orderBy('nombre')->get();

        if ($tiendaId = $request->query('tienda_id')) {
            $inventario = Inventario::where('tienda_id', $tiendaId)
                ->whereIn('producto_id', $productos->pluck('id'))
                ->get()
                ->keyBy('producto_id');

            $productos = $productos->map(function ($p) use ($inventario) {
                $inv = $inventario->get($p->id);
                $p->stock_disponible = $inv?->cantidad_disponible ?? 0;
                $p->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
                $p->stock_minimo     = $inv?->stock_minimo        ?? 1;
                return $p;
            });
        }

        return response()->json($productos->values());
    }

    /**
     * GET /api/productos/{id}?tienda_id=1
     */
    public function show(Request $request, int $id)
    {
        $producto = Producto::where('activo', true)->findOrFail($id);

        if ($tiendaId = $request->query('tienda_id')) {
            $inv = Inventario::where('producto_id', $id)
                ->where('tienda_id', $tiendaId)
                ->first();

            $producto->stock_disponible = $inv?->cantidad_disponible ?? 0;
            $producto->stock_reservado  = $inv?->cantidad_reservada  ?? 0;
            $producto->stock_minimo     = $inv?->stock_minimo        ?? 1;
        }

        return response()->json($producto);
    }

    /**
     * PATCH /api/productos/{id}
     *
     * Permite cambiar solo el precio_base del producto.
     * Accesible por vendedores y supervisores.
     */
    public function update(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $data = $request->validate([
            'precio_base' => 'required|numeric|min:0',
        ]);

        $producto->update(['precio_base' => $data['precio_base']]);

        return response()->json($producto);
    }
}
