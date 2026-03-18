<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Ambil semua user
     */
    public function index(Request $request)
    {
        // Optional: hanya admin yang boleh akses
        if (Auth::user()->role !== 'admin') {
            return response()->json("tidak dikenali");
        }

        $query = User::select('id', 'name', 'email', 'role');

        // Search by name/email when query is at least 3 characters
        if ($request->filled('search')) {
            $search = trim($request->get('search'));

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('id', 'desc')->get();

        return response()->json($users);
    }

    /**
     * Update role user
     */
    public function updateRole(Request $request, $id)
    {
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $user = User::findOrFail($id);

        // Jangan biarkan admin mengubah dirinya sendiri jadi peserta
        if ($user->id === Auth::id() && $request->role !== 'admin') {
            return response()->json([
                'message' => 'Tidak bisa menurunkan role diri sendiri'
            ], 400);
        }

        $user->role = $request->role;
        $user->save();

        return response()->json([
            'message' => 'Role berhasil diperbarui'
        ]);
    }
}