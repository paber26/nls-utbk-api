<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use Illuminate\Http\Request;

class TryoutController extends Controller
{
    // 🔹 GET /api/tryout
    public function index(Request $request)
    {
        $query = Tryout::with(['komponen', 'pembuat'])
            ->withCount(['questions as total_soal']);

        // 🔹 Filter berdasarkan mapel_id
        if ($request->filled('mapel_id')) {
            $query->whereHas('komponen', function ($q) use ($request) {
                $q->where('komponen_id', $request->mapel_id);
            });
        }

        $data = $query
            ->latest()
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'paket' => $item->paket,
                    'komponen_id' => $item->komponen->pluck('id')->toArray(),
                    'komponen' => $item->komponen->pluck('nama_komponen')->join(', ') ?: '-',
                    'total_soal' => $item->total_soal ?? 0,
                    'status' => $item->status,
                    'show_pembahasan' => (bool) $item->show_pembahasan,
                    'pembuat' => $item->pembuat->name ?? '-',
                    'mulai' => $item->mulai,
                    'selesai' => $item->selesai,
                    'created_at' => optional($item->created_at)->format('Y-m-d'),
                ];
            });

        return response()->json($data);
    }

    // 🔹 GET /api/tryout/{id}
    public function show($id)
    {
        $tryout = Tryout::with('komponen')->findOrFail($id);

        return response()->json([
            'id'            => $tryout->id,
            'paket'         => $tryout->paket,
            'komponen_id'      => $tryout->komponen->pluck('id')->toArray(),
            'komponen_nama'    => $tryout->komponen->pluck('nama_komponen')->join(', ') ?: '-',
            'tingkat'       => $tryout->komponen->pluck('mata_uji')->join(', ') ?: '-',
            'durasi_menit'  => $tryout->durasi_menit,
            'mulai'         => $tryout->mulai,
            'selesai'       => $tryout->selesai,
            'status'        => $tryout->status,
            'ketentuan_khusus' => $tryout->ketentuan_khusus,
            'pesan_selesai' => $tryout->pesan_selesai,
            'show_pembahasan' => (bool) $tryout->show_pembahasan,
        ]);
    }

    // 🔹 POST /api/tryout
    public function store(Request $request)
    {
        $data = $request->validate([
            'paket'        => 'required|string|max:255',
            'komponen_id'  => 'required|array',
            'komponen_id.*'=> 'integer|exists:komponen,id',
            'durasi_menit' => 'required|integer',
            'mulai'        => 'required|date',
            'selesai'      => 'required|date',
            // 'status'       => 'required|in:draft,active,finished',
        ]);
            
        $tryout = Tryout::create([
            'paket'        => $data['paket'],
            'durasi_menit' => $data['durasi_menit'],
            'mulai'        => $data['mulai'],
            'selesai'      => $data['selesai'],
            // 'status'       => $data['status'],
            'created_by'   => $request->user()?->id,
        ]);

        $komponenData = [];
        foreach ($data['komponen_id'] as $index => $id) {
            $komponenData[$id] = ['urutan' => $index + 1];
        }
        $tryout->komponen()->sync($komponenData);

        return response()->json([
            'success' => true,
            'message' => 'Tryout berhasil dibuat',
            'data'    => $tryout
        ], 201);
    }

    // 🔹 PUT /api/tryout/{id}
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'paket'        => 'required|string|max:255',
            'komponen_id'  => 'required|array',
            'komponen_id.*'=> 'integer|exists:komponen,id',
            'durasi_menit' => 'required|integer',
            'mulai'        => 'required|date',
            'selesai'      => 'required|date',
            'status'       => 'required|in:draft,active,finished',
            'ketentuan_khusus' => 'nullable|string',
            'pesan_selesai' => 'nullable|string'
        ]);

        $tryout = Tryout::findOrFail($id);

        $tryout->update([
            'paket'        => $data['paket'],
            'durasi_menit' => $data['durasi_menit'],
            'mulai'        => $data['mulai'],
            'selesai'      => $data['selesai'],
            'status'       => $data['status'],
            'created_by'   => $request->user()?->id,
            'ketentuan_khusus' => $data['ketentuan_khusus'],
            'pesan_selesai' => $data['pesan_selesai'],
        ]);

        $komponenData = [];
        foreach ($data['komponen_id'] as $index => $id) {
            $komponenData[$id] = ['urutan' => $index + 1];
        }
        $tryout->komponen()->sync($komponenData);

        return response()->json([
            'success' => true,
            'message' => 'Tryout berhasil diperbarui',
            'data'    => $tryout
        ]);
    }
}
