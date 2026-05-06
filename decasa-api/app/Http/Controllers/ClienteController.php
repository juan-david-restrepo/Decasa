<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Orden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClienteController extends Controller
{
    /**
     * GET /api/clientes?search=juan&page=1&tipo=interesado
     * Búsqueda por nombre, cédula o teléfono con paginación.
     * Filtro opcional por tipo: oficial|interesado
     */
    public function index(Request $request)
    {
        $query = Cliente::query();

        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', $term)
                  ->orWhere('cedula', 'like', $term)
                  ->orWhere('telefono', 'like', $term);
            });
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        $clientes = $query->orderBy('nombre')->paginate(20);

        return response()->json($clientes);
    }

    /**
     * POST /api/clientes
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:120',
            'cedula'           => 'nullable|string|max:20|unique:clientes,cedula',
            'telefono'         => 'nullable|string|max:20',
            'email'            => 'nullable|email|max:120',
            'direccion'        => 'nullable|string|max:200',
            'canal_pref'       => 'nullable|in:fisica,whatsapp,red_social,otro',
            'tipo'             => 'nullable|in:oficial,interesado',
            'categorias_interes' => 'nullable|array',
            'categorias_interes.*' => 'string|max:50',
            'notas_interes'    => 'nullable|string|max:1000',
        ]);

        $cliente = Cliente::create($data);

        return response()->json($cliente, 201);
    }

    /**
     * PUT /api/clientes/{id}
     * Actualiza campos del cliente (ej. tipo).
     */
    public function update(Request $request, int $id)
    {
        $cliente = Cliente::findOrFail($id);

        $data = $request->validate([
            'nombre'            => 'sometimes|string|max:120',
            'cedula'            => 'sometimes|string|max:20|unique:clientes,cedula,' . $id,
            'telefono'          => 'sometimes|nullable|string|max:20',
            'email'             => 'sometimes|nullable|email|max:120',
            'direccion'         => 'sometimes|nullable|string|max:200',
            'canal_pref'        => 'sometimes|nullable|in:fisica,whatsapp,red_social,otro',
            'tipo'              => 'sometimes|in:oficial,interesado',
            'categorias_interes' => 'sometimes|nullable|array',
            'categorias_interes.*' => 'string|max:50',
            'notas_interes'     => 'sometimes|nullable|string|max:1000',
        ]);

        $cliente->update($data);

        return response()->json($cliente);
    }

    /**
     * GET /api/clientes/{id}
     * Retorna el cliente con sus estadísticas.
     */
    public function show(int $id)
    {
        $cliente = Cliente::findOrFail($id);

        // Estadísticas en una sola query
        $stats = DB::table('ordenes as o')
            ->leftJoin('pagos as p', 'p.orden_id', '=', 'o.id')
            ->where('o.cliente_id', $id)
            ->selectRaw('
                COUNT(DISTINCT o.id)                          AS total_ordenes,
                MAX(o.created_at)                             AS ultima_compra,
                SUM(o.valor_total) - COALESCE(SUM(p.monto),0) AS saldo_pendiente_total
            ')
            ->first();

        // Canal más usado
        $canalFrec = DB::table('ordenes')
            ->where('cliente_id', $id)
            ->whereNotNull('canal')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->groupBy('canal')
            ->orderByDesc('total')
            ->value('canal');

        $cliente->total_ordenes        = (int) ($stats->total_ordenes ?? 0);
        $cliente->ultima_compra        = $stats->ultima_compra;
        $cliente->saldo_pendiente_total = (float) ($stats->saldo_pendiente_total ?? 0);
        $cliente->canal_frecuente      = $canalFrec;

        return response()->json($cliente);
    }

    /**
     * GET /api/clientes/{id}/ordenes
     */
    public function ordenes(Request $request, int $id)
    {
        Cliente::findOrFail($id);

        $ordenes = Orden::with([
            'tienda:id,nombre',
            'vendedor:id,nombre',
        ])
            ->withSum('pagos', 'monto')
            ->where('cliente_id', $id)
            ->orderByDesc('created_at')
            ->paginate(15);

        $ordenes->getCollection()->transform(function ($o) {
            $o->total_pagado    = (float) ($o->pagos_sum_monto ?? 0);
            $o->saldo_pendiente = (float) $o->valor_total - $o->total_pagado;
            return $o;
        });

        return response()->json($ordenes);
    }
}
