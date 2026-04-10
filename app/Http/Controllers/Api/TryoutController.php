<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use Illuminate\Http\Request;

class TryoutController extends Controller
{
    public function index(Request $request)
    {
        $query = Tryout::query()
            ->with(['mapel', 'pembuat'])
            ->withCount(['questions as total_soal']);

        if ($request->filled('mapel_id')) {
            $query->where('mapel_id', $request->mapel_id);
        }

        $data = $query->latest()->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'paket' => $item->paket,
                'mapel_id' => $item->mapel_id,
                'mapel' => $item->mapel?->nama ?? '-',
                'total_soal' => $item->total_soal ?? 0,
                'status' => $item->status,
                'show_pembahasan' => (bool) $item->show_pembahasan,
                'pembuat' => $item->pembuat?->name ?? '-',
                'mulai' => $item->mulai,
                'selesai' => $item->selesai,
                'created_at' => optional($item->created_at)->format('Y-m-d'),
            ];
        });

        return response()->json($data);
    }

    public function show($id)
    {
        $tryout = Tryout::with('mapel')->findOrFail($id);

        return response()->json([
            'id' => $tryout->id,
            'paket' => $tryout->paket,
            'mapel_id' => $tryout->mapel_id,
            'mapel_nama' => $tryout->mapel?->nama ?? '-',
            'tingkat' => $tryout->mapel?->tingkat ?? '-',
            'durasi_menit' => $tryout->durasi_menit,
            'mulai' => $tryout->mulai,
            'selesai' => $tryout->selesai,
            'status' => $tryout->status,
            'ketentuan_khusus' => $tryout->ketentuan_khusus,
            'pesan_selesai' => $tryout->pesan_selesai,
            'show_pembahasan' => (bool) $tryout->show_pembahasan,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'paket' => 'required|string|max:255',
            'mapel_id' => 'required|integer|exists:mapel,id',
            'durasi_menit' => 'required|integer|min:1',
            'mulai' => 'required|date',
            'selesai' => 'required|date|after_or_equal:mulai',
            'access_key' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,active,finished',
            'ketentuan_khusus' => 'nullable|string',
            'pesan_selesai' => 'nullable|string',
        ]);

        $tryout = Tryout::create([
            'paket' => $data['paket'],
            'mapel_id' => $data['mapel_id'],
            'durasi_menit' => $data['durasi_menit'],
            'mulai' => $data['mulai'],
            'selesai' => $data['selesai'],
            'status' => $data['status'] ?? 'draft',
            'created_by' => $request->user()?->id,
            'ketentuan_khusus' => $data['ketentuan_khusus'] ?? null,
            'pesan_selesai' => $data['pesan_selesai'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tryout berhasil dibuat',
            'data' => $tryout,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'paket' => 'required|string|max:255',
            'mapel_id' => 'required|integer|exists:mapel,id',
            'durasi_menit' => 'required|integer|min:1',
            'mulai' => 'required|date',
            'selesai' => 'required|date|after_or_equal:mulai',
            'status' => 'required|in:draft,active,finished',
            'access_key' => 'nullable|string|max:255',
            'ketentuan_khusus' => 'nullable|string',
            'pesan_selesai' => 'nullable|string',
        ]);

        $tryout = Tryout::findOrFail($id);
        $tryout->update([
            'paket' => $data['paket'],
            'mapel_id' => $data['mapel_id'],
            'durasi_menit' => $data['durasi_menit'],
            'mulai' => $data['mulai'],
            'selesai' => $data['selesai'],
            'status' => $data['status'],
            'created_by' => $request->user()?->id,
            'ketentuan_khusus' => $data['ketentuan_khusus'] ?? null,
            'pesan_selesai' => $data['pesan_selesai'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tryout berhasil diperbarui',
            'data' => $tryout,
        ]);
    }
}
