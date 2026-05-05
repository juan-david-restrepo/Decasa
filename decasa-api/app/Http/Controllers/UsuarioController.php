<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $query = Usuario::with('tiendaDefault:id,nombre,ciudad');

        if ($rol = $request->query('rol')) {
            $query->where('rol', $rol);
        }

        if ($search = $request->query('search')) {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('nombre', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        $usuarios = $query->orderBy('nombre')->get()->map(function ($u) {
            return [
                'id'                => $u->id,
                'nombre'            => $u->nombre,
                'email'             => $u->email,
                'rol'               => $u->rol,
                'tienda_default_id' => $u->tienda_default_id,
                'tienda_default'    => $u->tiendaDefault,
                'activo'            => $u->activo,
            ];
        });

        return response()->json($usuarios);
    }

    public function show($id)
    {
        $usuario = Usuario::with('tiendaDefault:id,nombre,ciudad')->findOrFail($id);

        return response()->json([
            'id'                => $usuario->id,
            'nombre'            => $usuario->nombre,
            'email'             => $usuario->email,
            'rol'               => $usuario->rol,
            'tienda_default_id' => $usuario->tienda_default_id,
            'tienda_default'    => $usuario->tiendaDefault,
            'activo'            => $usuario->activo,
            'created_at'        => $usuario->created_at,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'            => 'required|string|max:100',
            'email'             => 'required|email|unique:usuarios,email',
            'password'          => 'required|string|min:8|confirmed',
            'rol'               => ['required', Rule::in(['vendedor', 'supervisor'])],
            'tienda_default_id' => 'required|exists:tiendas,id',
        ], [
            'nombre.required'            => 'El nombre es obligatorio.',
            'nombre.max'                 => 'El nombre no puede tener más de 100 caracteres.',
            'email.required'             => 'El email es obligatorio.',
            'email.email'                => 'El email debe ser una dirección válida.',
            'email.unique'               => 'Este email ya está registrado.',
            'password.required'          => 'La contraseña es obligatoria.',
            'password.min'               => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'         => 'Las contraseñas no coinciden.',
            'rol.required'               => 'El rol es obligatorio.',
            'rol.in'                     => 'El rol debe ser vendedor o supervisor.',
            'tienda_default_id.required' => 'La tienda predeterminada es obligatoria.',
            'tienda_default_id.exists'   => 'La tienda seleccionada no existe.',
        ]);

        $usuario = Usuario::create([
            'nombre'            => $data['nombre'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'rol'               => $data['rol'],
            'tienda_default_id' => $data['tienda_default_id'],
            'activo'            => true,
        ]);

        return response()->json([
            'id'                => $usuario->id,
            'nombre'            => $usuario->nombre,
            'email'             => $usuario->email,
            'rol'               => $usuario->rol,
            'tienda_default_id' => $usuario->tienda_default_id,
            'activo'            => $usuario->activo,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $usuario = Usuario::findOrFail($id);

        $data = $request->validate([
            'nombre'            => 'sometimes|string|max:100',
            'email'             => ['sometimes', 'email', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'rol'               => ['sometimes', Rule::in(['vendedor', 'supervisor'])],
            'tienda_default_id' => 'sometimes|exists:tiendas,id',
        ], [
            'nombre.max'               => 'El nombre no puede tener más de 100 caracteres.',
            'email.email'              => 'El email debe ser una dirección válida.',
            'email.unique'             => 'Este email ya está registrado.',
            'rol.in'                   => 'El rol debe ser vendedor o supervisor.',
            'tienda_default_id.exists' => 'La tienda seleccionada no existe.',
        ]);

        $usuario->update($data);
        $usuario->load('tiendaDefault:id,nombre,ciudad');

        return response()->json([
            'id'                => $usuario->id,
            'nombre'            => $usuario->nombre,
            'email'             => $usuario->email,
            'rol'               => $usuario->rol,
            'tienda_default_id' => $usuario->tienda_default_id,
            'tienda_default'    => $usuario->tiendaDefault,
            'activo'            => $usuario->activo,
        ]);
    }

    public function toggleActivo($id)
    {
        $usuario = Usuario::findOrFail($id);

        if ($usuario->id === auth()->id()) {
            abort(403, 'No puedes desactivar tu propia cuenta.');
        }

        $usuario->activo = !$usuario->activo;
        $usuario->save();

        return response()->json([
            'id'     => $usuario->id,
            'activo' => $usuario->activo,
        ]);
    }

    public function resetPassword(Request $request, $id)
    {
        $data = $request->validate([
            'password' => 'required|string|min:8',
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $usuario = Usuario::findOrFail($id);

        $usuario->update([
            'password' => Hash::make($data['password']),
        ]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}
