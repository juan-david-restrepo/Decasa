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
     * GET /api/inventario?tienda_id=todas&search=sofa
     *
     * Devuelve inventario de una tienda o de todas las tiendas (agrupado).
     */
    public function index(Request $request)
    {
        $request->validate([
            'tienda_id' => 'required',
        ]);

        $tiendaId = $request->query('tienda_id');
        $search   = $request->query('search');

        if ($tiendaId === 'todas') {
            return $this->inventarioTodas($search);
        }

        $request->validate([
            'tienda_id' => 'exists:tiendas,id',
        ]);

        $query = Inventario::with('producto:id,nombre,categoria,precio_base,foto_url,personalizable,activo')
            ->where('tienda_id', $tiendaId)
            ->whereHas('producto', fn($q) => $q->where('activo', true));

        if ($search) {
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
     * Devuelve inventario agrupado por producto de TODAS las tiendas.
     */
    private function inventarioTodas($search = null)
    {
        $query = DB::table('inventario')
            ->join('productos', 'productos.id', '=', 'inventario.producto_id')
            ->where('productos.activo', true)
            ->select(
                'productos.id as producto_id',
                'productos.nombre',
                'productos.categoria',
                'productos.precio_base',
                'productos.foto_url',
                'productos.personalizable',
                DB::raw('SUM(inventario.cantidad_disponible) as cantidad_disponible'),
                DB::raw('SUM(inventario.cantidad_reservada) as cantidad_reservada'),
                DB::raw('COUNT(DISTINCT inventario.tienda_id) as tiendas_count')
            )
            ->groupBy(
                'productos.id',
                'productos.nombre',
                'productos.categoria',
                'productos.precio_base',
                'productos.foto_url',
                'productos.personalizable'
            );

        if ($search) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('productos.nombre', 'like', $term)
                  ->orWhere('productos.categoria', 'like', $term);
            });
        }

        $results = $query
            ->orderBy('productos.nombre')
            ->get()
            ->map(function ($inv) {
                return (object) [
                    'id'                  => $inv->producto_id,
                    'producto_id'         => $inv->producto_id,
                    'cantidad_disponible' => $inv->cantidad_disponible,
                    'cantidad_reservada'  => $inv->cantidad_reservada,
                    'stock_libre'         => $inv->cantidad_disponible - $inv->cantidad_reservada,
                    'stock_minimo'        => 0,
                    'bajo_stock'          => false,
                    'tiendas_count'       => $inv->tiendas_count,
                    'producto'            => (object) [
                        'id'             => $inv->producto_id,
                        'nombre'         => $inv->nombre,
                        'categoria'      => $inv->categoria,
                        'precio_base'    => $inv->precio_base,
                        'foto_url'       => $inv->foto_url,
                        'personalizable' => (bool) $inv->personalizable,
                    ],
                ];
            });

        return response()->json($results);
    }

    /**
     * POST /api/inventario/entrada
     * Agrega stock a una tienda o a todas las tiendas donde existe el producto.
     */
    public function entrada(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tienda_id'   => 'required',
            'cantidad'    => 'required|integer|min:1',
            'motivo'      => 'nullable|string|max:200',
        ]);

        if ($data['tienda_id'] === 'todas') {
            if ($request->user()->rol !== 'supervisor') {
                abort(403, 'Solo los supervisores pueden agregar stock a todas las tiendas.');
            }
            return $this->entradaTodas($data, $request);
        }

        $request->validate([
            'tienda_id' => 'exists:tiendas,id',
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

    /**
     * Agrega stock a TODAS las tiendas donde existe el producto.
     */
    private function entradaTodas($data, $request)
    {
        $inventario = Inventario::with('tienda:id,nombre')->where('producto_id', $data['producto_id'])->get();

        if ($inventario->isEmpty()) {
            abort(422, 'El producto no existe en ninguna tienda.');
        }

        $motivo = $data['motivo'] ?? 'Entrada manual (todas las tiendas)';
        $usuarioId = $request->user()->id;

        DB::transaction(function () use ($inventario, $data, $motivo, $usuarioId) {
            foreach ($inventario as $inv) {
                Inventario::where('id', $inv->id)
                    ->increment('cantidad_disponible', $data['cantidad']);

                $tiendaNombre = $inv->tienda ? $inv->tienda->nombre : "Tienda #{$inv->tienda_id}";

                InventarioMovimiento::create([
                    'producto_id' => $data['producto_id'],
                    'tienda_id'   => $inv->tienda_id,
                    'tipo'        => 'entrada',
                    'cantidad'    => $data['cantidad'],
                    'motivo'      => "{$motivo} — {$tiendaNombre}",
                    'usuario_id'  => $usuarioId,
                ]);
            }
        });

        $producto = \App\Models\Producto::find($data['producto_id']);

        return response()->json([
            'producto_id'       => $data['producto_id'],
            'producto'          => (object) [
                'id'          => $producto->id,
                'nombre'      => $producto->nombre,
                'categoria'   => $producto->categoria,
                'precio_base' => $producto->precio_base,
            ],
            'cantidad_disponible' => $inventario->sum('cantidad_disponible') + ($data['cantidad'] * $inventario->count()),
            'cantidad_reservada'  => $inventario->sum('cantidad_reservada'),
            'stock_libre'         => $inventario->sum(function ($i) {
                return $i->cantidad_disponible - $i->cantidad_reservada;
            }) + ($data['cantidad'] * $inventario->count()),
            'stock_minimo'        => 0,
            'bajo_stock'          => false,
            'tiendas_count'       => $inventario->count(),
            'tiendas_afectadas'   => $inventario->count(),
        ], 201);
    }
}
