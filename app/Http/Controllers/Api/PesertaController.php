<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PesertaController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'peserta')
            ->with('sekolah');

        // SEARCH (nama/email)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // FILTER SEKOLAH
        if ($request->filled('sekolah')) {
            $query->where('sekolah_nama', 'like', "%{$request->sekolah}%");
        }

        // FILTER KELAS
        if ($request->filled('kelas')) {
            $query->where('kelas', $request->kelas);
        }

        // FILTER STATUS PROFIL
        if ($request->filled('status')) {
            if ($request->status === 'Lengkap') {
                $query->whereNotNull('nama_lengkap')
                      ->whereNotNull('sekolah_id')
                      ->whereNotNull('kelas')
                      ->whereNotNull('whatsapp');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('nama_lengkap')
                      ->orWhereNull('sekolah_id')
                      ->orWhereNull('kelas')
                      ->orWhereNull('whatsapp');
                });
            }
        }

        $perPage = $request->get('per_page', 10);

        $peserta = $query
            ->select(
                'id',
                'nama_lengkap',
                'email',
                'sekolah_id',
                'sekolah_nama',
                'kelas',
                'whatsapp',
                'created_at'
            )
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $peserta->getCollection()->transform(function ($user) {

            $profilLengkap =
                !empty($user->nama_lengkap) &&
                !empty($user->sekolah_id) &&
                !empty($user->kelas) &&
                !empty($user->whatsapp);

            return [
                'id' => $user->id,
                'nama_lengkap' => $user->nama_lengkap,
                'email' => $user->email,
                'sekolah' => optional($user->sekolah)->nama,
                'sekolah_nama' => $user->sekolah_nama,
                'kelas' => $user->kelas,
                'whatsapp' => $user->whatsapp,
                'status_profil' => $profilLengkap ? 'Lengkap' : 'Belum Lengkap',
            ];
        });

        return response()->json($peserta);
    }

    public function show($id)
    {
        $peserta = User::with([
            'sekolah',
            'attempts.tryout'
        ])->findOrFail($id);

        return response()->json([
            'id' => $peserta->id,
            'nama_lengkap' => $peserta->nama_lengkap,
            'email' => $peserta->email,
            'kelas' => $peserta->kelas,
            'whatsapp' => $peserta->whatsapp,
            'provinsi' => $peserta->provinsi,
            'kota' => $peserta->kota,
            'kecamatan' => $peserta->kecamatan,
            'role' => $peserta->role,
            'is_event_registered' => $peserta->is_event_registered,
            'sekolah' => $peserta->sekolah ? [
                'id' => $peserta->sekolah->id,
                'nama' => $peserta->sekolah->nama,
            ] : null,
            'detail_tryout' => $peserta->attempts->map(function ($attempt) {
                return [
                    'attempt_id' => $attempt->id,
                    'tryout_id' => $attempt->tryout_id,
                    'nama_tryout' => optional($attempt->tryout)->paket,
                    'status' => $attempt->status,
                    'mulai' => $attempt->mulai,
                    'selesai' => $attempt->selesai,
                ];
            }),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sekolah_id' => 'required|exists:sekolah,id',
            'nama_lengkap' => 'required|string|max:255',
            'kelas' => 'required|string|max:10',
            'email' => 'required|email|unique:users,email',
            'whatsapp' => 'required|string|max:20',
        ]);

        $user = User::create([
            'sekolah_id' => $validated['sekolah_id'],
            'name' => $validated['nama_lengkap'],
            'nama_lengkap' => $validated['nama_lengkap'],
            'kelas' => $validated['kelas'],
            'email' => $validated['email'],
            'whatsapp' => $validated['whatsapp'],
            'password' => Hash::make($validated['whatsapp']),
            'role' => 'peserta',
            'is_active' => 1,
            'is_event_registered' => 1,
        ]);

        return response()->json([
            'message' => 'Peserta berhasil ditambahkan',
            'data' => $user
        ], 201);
    }
    public function toggleEvent($id)
    {
        $user = User::findOrFail($id);

        // Toggle nilai is_event_registered (0 ↔ 1)
        $user->is_event_registered = $user->is_event_registered ? 0 : 1;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Status event berhasil diperbarui',
            'is_event_registered' => $user->is_event_registered
        ]);
    }

    public function riwayatTryout($id)
    {
        $user = User::findOrFail($id);

        $attempts = $user->attempts()
            ->with('tryout')
            // ->where('status', 'submitted') // hanya yang sudah selesai
            ->orderByDesc('selesai')       // terbaru dulu
            ->get();

        $riwayat = $attempts->map(function ($attempt) {
            return [
                'attempt_id'  => $attempt->id,
                'tryout_id'   => $attempt->tryout_id,
                'nama_tryout' => optional($attempt->tryout)->paket ?? '-',
                'status'      => $attempt->status,
                'nilai'       => $attempt->nilai ?? 0,
                'mulai'       => $attempt->mulai,
                'selesai'     => $attempt->selesai,
            ];
        });

        return response()->json([
            'user_id'      => $user->id,
            'nama_peserta' => $user->nama_lengkap ?? $user->name,
            'total_tryout' => $riwayat->count(),
            'riwayat'      => $riwayat,
        ]);
    }
}