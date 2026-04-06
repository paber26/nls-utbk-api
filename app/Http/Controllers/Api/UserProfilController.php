<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserProfilController extends Controller
{
    /**
     * Simpan / update profil peserta
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        $data = [
            'nama_lengkap' => $request->nama_lengkap,
            'sekolah_id'   => $request->sekolah_id,
            'kelas'        => $request->kelas,
            'provinsi'     => $request->provinsi,
            'kota'         => $request->kota,
            'kecamatan'    => $request->kecamatan,
            'whatsapp'     => $request->whatsapp,
            'minat'        => $request->minat,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }


        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profil peserta berhasil disimpan'
        ]);
    }

    public function profile(Request $request)
    {
        // return 'oke';
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'nama_lengkap' => $user->nama_lengkap,
            'email' => $user->email,
            'sekolah_id' => $user->sekolah_id,
            'sekolah_nama' => $user->sekolah_nama,
            'kelas' => $user->kelas,
            'whatsapp' => $user->whatsapp,
            'provinsi' => $user->provinsi,
            'kota' => $user->kota,
            'kecamatan' => $user->kecamatan,
            'minat' => $user->minat,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user(); // user yang sedang login

        $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'kelas' => 'required|string|max:50',
            'whatsapp' => 'required|string|max:20',
            'provinsi' => 'required|string',
            'kota' => 'required|string',
            'kecamatan' => 'required|string',
        ]);

        $user->update([
            'nama_lengkap' => $request->nama_lengkap,
            'kelas' => $request->kelas,
            'whatsapp' => $request->whatsapp,
            'provinsi' => $request->provinsi,
            'kota' => $request->kota,
            'kecamatan' => $request->kecamatan,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui',
            'data' => $user
        ]);
    }
}