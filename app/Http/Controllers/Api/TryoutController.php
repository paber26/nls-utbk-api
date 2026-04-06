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
                    'komponen' => $item->komponen->map(function ($k) {
                        return [
                            'id' => $k->id,
                            'nama_komponen' => $k->nama_komponen,
                            'durasi_menit' => $k->pivot->durasi_menit
                        ];
                    }),
                    'komponen_text' => $item->komponen->pluck('nama_komponen')->join(', ') ?: '-',
                    'total_soal' => $item->total_soal ?? 0,
                    'status' => $item->status,
                    'access_key' => $item->access_key,
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
            'komponen'         => $tryout->komponen->map(function ($k) {
                return [
                    'id' => $k->id,
                    'nama_komponen' => $k->nama_komponen,
                    'durasi_menit' => $k->pivot->durasi_menit
                ];
            }),
            'komponen_nama'    => $tryout->komponen->pluck('nama_komponen')->join(', ') ?: '-',
            'tingkat'       => $tryout->komponen->pluck('mata_uji')->join(', ') ?: '-',
            'durasi_menit'  => $tryout->durasi_menit,
            'mulai'         => $tryout->mulai,
            'selesai'       => $tryout->selesai,
            'status'        => $tryout->status,
            'access_key'    => $tryout->access_key,
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
            'komponen'     => 'required|array',
            'komponen.*.id'=> 'required|integer|exists:komponen,id',
            'komponen.*.durasi_menit' => 'required|integer|min:1',
            'mulai'        => 'required|date',
            'selesai'      => 'required|date',
            'access_key'   => 'nullable|string|max:255',
            // 'status'       => 'required|in:draft,active,finished',
        ]);
            
        $totalDurasi = collect($data['komponen'])->sum('durasi_menit');

        $tryout = Tryout::create([
            'paket'        => $data['paket'],
            'durasi_menit' => $totalDurasi,
            'mulai'        => $data['mulai'],
            'selesai'      => $data['selesai'],
            'access_key'   => $data['access_key'],
            // 'status'       => $data['status'],
            'created_by'   => $request->user()?->id,
        ]);

        $komponenData = [];
        foreach ($data['komponen'] as $index => $item) {
            $komponenData[$item['id']] = [
                'urutan' => $index + 1,
                'durasi_menit' => $item['durasi_menit']
            ];
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
            'komponen'     => 'required|array',
            'komponen.*.id'=> 'required|integer|exists:komponen,id',
            'komponen.*.durasi_menit' => 'required|integer|min:1',
            'mulai'        => 'required|date',
            'selesai'      => 'required|date',
            'status'       => 'required|in:draft,active,finished',
            'access_key'   => 'nullable|string|max:255',
            'ketentuan_khusus' => 'nullable|string',
            'pesan_selesai' => 'nullable|string'
        ]);

        $tryout = Tryout::findOrFail($id);

        $totalDurasi = collect($data['komponen'])->sum('durasi_menit');

        $tryout->update([
            'paket'        => $data['paket'],
            'durasi_menit' => $totalDurasi,
            'mulai'        => $data['mulai'],
            'selesai'      => $data['selesai'],
            'status'       => $data['status'],
            'access_key'   => $data['access_key'],
            'created_by'   => $request->user()?->id,
            'ketentuan_khusus' => $data['ketentuan_khusus'],
            'pesan_selesai' => $data['pesan_selesai'],
        ]);

        $komponenData = [];
        foreach ($data['komponen'] as $index => $item) {
            $komponenData[$item['id']] = [
                'urutan' => $index + 1,
                'durasi_menit' => $item['durasi_menit']
            ];
        }
        $tryout->komponen()->sync($komponenData);

        return response()->json([
            'success' => true,
            'message' => 'Tryout berhasil diperbarui',
            'data'    => $tryout
        ]);
    }
}
