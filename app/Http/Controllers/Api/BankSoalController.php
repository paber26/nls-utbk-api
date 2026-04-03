<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankSoal;
use App\Models\OpsiJawaban;
use App\Models\BankSoalPernyataan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankSoalController extends Controller
{
    // 🔹 GET /api/bank-soal
    public function index(Request $request)
    {
        $query = BankSoal::with(['komponen', 'pembuat']);

        // 🔍 Filter by komponen (by name)
        if ($request->filled('komponen')) {
            $query->whereHas('komponen', function ($q) use ($request) {
                $q->where('nama_komponen', $request->komponen);
            });
        }

        // 🔍 Filter by status (if column exists)php artisan tinker
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->get('per_page', 50);

        $paginated = $query
            ->latest()
            ->paginate($perPage);

        $paginated->getCollection()->transform(function ($item) {
            $jumlahTerpakai = DB::table('tryout_soal')
                ->where('banksoal_id', $item->id)
                ->count();

            return [
                'id' => $item->id,
                'pertanyaan' => $item->pertanyaan,
                'komponen' => $item->komponen->nama_komponen ?? '-',
                'pembuat' => $item->pembuat->name ?? '-',
                'jumlah_terpakai' => $jumlahTerpakai,
            ];
        });

        return response()->json($paginated);
    }

    /**
     * 🔹 GET /api/banksoal/tryout
     * Digunakan khusus untuk pemilihan soal ke tryout
     * (ringan & tanpa perhitungan tambahan)
     */
    public function listForTryout(Request $request)
    {
        $query = BankSoal::query()
            ->with('komponen:id,nama_komponen');

        // 🔍 filter keyword soal
        if ($request->filled('q')) {
            $query->where('pertanyaan', 'like', '%' . $request->q . '%');
        }

        // 🔍 filter komponen
        if ($request->filled('komponen_id')) {
            $query->where('komponen_id', $request->komponen_id);
        }

        return response()->json(
            $query->latest()->get()->map(function ($soal) {
                return [
                    'id'          => $soal->id,
                    'pertanyaan'  => $soal->pertanyaan,
                    'komponen_id'    => $soal->komponen_id,
                    'komponen_nama'  => $soal->komponen?->nama_komponen,
                ];
            })
        );
    }


    // 🔹 POST /api/bank-soal
    public function store(Request $request)
    {
        // Normalize komponen payloads:
        // - frontend may send `komponen` object, numeric id, or JSON string
        // - older clients may still send `mapel_id`
        if (! $request->filled('komponen_id')) {
            if ($request->filled('komponen')) {
                $komponen = $request->komponen;

                if (is_array($komponen) && isset($komponen['id'])) {
                    $request->merge(['komponen_id' => $komponen['id']]);
                } elseif (is_object($komponen) && isset($komponen->id)) {
                    $request->merge(['komponen_id' => $komponen->id]);
                } elseif (is_numeric($komponen)) {
                    $request->merge(['komponen_id' => $komponen]);
                } elseif (is_string($komponen)) {
                    $decoded = json_decode($komponen, true);
                    if (is_array($decoded) && isset($decoded['id'])) {
                        $request->merge(['komponen_id' => $decoded['id']]);
                    } elseif (ctype_digit($komponen)) {
                        $request->merge(['komponen_id' => $komponen]);
                    }
                }
            } elseif ($request->filled('mapel_id')) {
                $request->merge(['komponen_id' => $request->mapel_id]);
            }
        }

        $request->validate([
            'komponen_id' => 'required|exists:komponen,id',
            'tipe' => 'required|in:pg,pg_majemuk,isian,pg_kompleks',
            'pertanyaan' => 'required|string',
            'pembahasan' => 'nullable|string',
            'jawaban_isian' => 'nullable|string',
            'opsi_jawaban' => 'required_if:tipe,pg,pg_majemuk|array',
            'opsi_jawaban.*.text' => 'required_if:tipe,pg,pg_majemuk|string',
            'opsi_jawaban.*.poin' => 'nullable|numeric',
            'opsi_jawaban.*.is_correct' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            // 1️⃣ Simpan soal utama
            $soal = BankSoal::create([
                'komponen_id'   => $request->komponen_id,
                'tipe'       => $request->tipe,
                'pertanyaan' => $request->pertanyaan,
                'pembahasan' => $request->pembahasan,
                'jawaban'    => $request->tipe === 'isian'
                    ? $request->jawaban_isian
                    : null,
                // 'created_by' => auth()->id() ?? 1,
                'created_by' => $request->user()?->id ?? 1,
                // 'created_by' => 1,
            ]);


            // 2️⃣ Jika PG atau PG Majemuk → simpan opsi
            if (in_array($request->tipe, ['pg', 'pg_majemuk'])) {
                $idOpsiBenar = null;

                foreach ($request->opsi_jawaban as $index => $opsi) {
                    $row = OpsiJawaban::create([
                        'soal_id'    => $soal->id,
                        'label'      => chr(65 + $index), // A, B, C, D, ...
                        'teks'       => $opsi['text'],
                        'poin'       => $opsi['poin'] ?? 0,
                        'is_correct' => $opsi['is_correct'] ?? false,
                    ]);

                    if ($request->tipe === 'pg' && !empty($opsi['is_correct'])) {
                        $idOpsiBenar = $row->id;
                    }
                }

                // 3️⃣ Update idopsijawaban hanya untuk PG tunggal
                if ($request->tipe === 'pg') {
                    $soal->update([
                        'idopsijawaban' => $idOpsiBenar
                    ]);
                }
            }
            

            // 2️⃣ Jika PG Kompleks → simpan pernyataan benar/salah
            if ($request->tipe === 'pg_kompleks') {
                foreach ($request->pernyataan as $index => $item) {
                    $cek = BankSoalPernyataan::create([
                        'banksoal_id'   => $soal->id,
                        'urutan'        => $index + 1,
                        'teks'          => $item['text'],
                        'jawaban_benar' => $item['jawaban'],
                    ]);
                    // return $cek;
                }

            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Soal berhasil disimpan'
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // 🔹 GET /api/bank-soal/{id}
    public function show($id)
    {
        $bankSoal = BankSoal::with(['komponen', 'opsiJawaban', 'pernyataanKompleks'])
            ->findOrFail($id);

        return response()->json([
            'id' => $bankSoal->id, 
            'komponen_id' => $bankSoal->komponen_id,
            'komponen_nama' => $bankSoal->komponen?->nama_komponen,
            'tipe' => $bankSoal->tipe,
            'pertanyaan' => $bankSoal->pertanyaan,
            'pembahasan' => $bankSoal->pembahasan,
            'jawaban' => $bankSoal->jawaban,
            'opsi_jawaban' => $bankSoal->opsiJawaban->map(function ($o) {
                return [
                    'text' => $o->teks,
                    'poin' => $o->poin,
                    'is_correct' => (bool) $o->is_correct,
                ];
            }),
            'pernyataan' => $bankSoal->pernyataanKompleks?->map(function ($p) {
                return [
                    'text' => $p->teks,
                    'jawaban' => (bool) $p->jawaban_benar,
                ];
            }),
        ]);
    }

    // 🔹 PUT /api/bank-soal/{id}
    public function update(Request $request, $id)
    {
        $bankSoal = BankSoal::findOrFail($id);

        // Accept payload with `komponen` object (from frontend) or `komponen_id`.
        if ($request->filled('komponen') && is_array($request->komponen) && isset($request->komponen['id'])) {
            $request->merge(['komponen_id' => $request->komponen['id']]);
        } elseif ($request->filled('komponen') && is_object($request->komponen) && isset($request->komponen->id)) {
            $request->merge(['komponen_id' => $request->komponen->id]);
        }

        $request->validate([
            'komponen_id' => 'required|exists:komponen,id',
            'tipe' => 'required|in:pg,pg_majemuk,isian,pg_kompleks',
            'pertanyaan' => 'required|string',
            'pembahasan' => 'nullable|string',
            'jawaban_isian' => 'nullable|string',
            'opsi_jawaban' => 'required_if:tipe,pg,pg_majemuk|array',
            'opsi_jawaban.*.text' => 'required_if:tipe,pg,pg_majemuk|string',
            'opsi_jawaban.*.poin' => 'nullable|numeric',
            'opsi_jawaban.*.is_correct' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            $bankSoal = BankSoal::findOrFail($id);

            // update soal utama
            $bankSoal->update([
                'komponen_id' => $request->komponen_id,
                'tipe' => $request->tipe,
                'pertanyaan' => $request->pertanyaan,
                'pembahasan' => $request->pembahasan,
                'jawaban' => $request->tipe === 'isian'
                    ? $request->jawaban_isian
                    : null,
                'idopsijawaban' => null,
            ]);

            // hapus opsi lama jika PG atau PG Majemuk
            if (in_array($request->tipe, ['pg', 'pg_majemuk'])) {

                // pastikan pernyataan kompleks dihapus jika sebelumnya tipe lain
                $bankSoal->pernyataanKompleks()->delete();

                OpsiJawaban::where('soal_id', $bankSoal->id)->delete();

                $idOpsiBenar = null;

                foreach ($request->opsi_jawaban as $index => $opsi) {
                    $row = OpsiJawaban::create([
                        'soal_id'    => $bankSoal->id,
                        'label'      => chr(65 + $index),
                        'teks'       => $opsi['text'],
                        'poin'       => $opsi['poin'] ?? 0,
                        'is_correct' => $opsi['is_correct'] ?? false,
                    ]);

                    // hanya PG tunggal yang menyimpan idopsijawaban
                    if ($request->tipe === 'pg' && !empty($opsi['is_correct'])) {
                        $idOpsiBenar = $row->id;
                    }
                }

                if ($request->tipe === 'pg') {
                    $bankSoal->update([
                        'idopsijawaban' => $idOpsiBenar
                    ]);
                }
            }

            // jika tipe bukan pg_kompleks, pastikan pernyataan lama dihapus
            if ($request->tipe !== 'pg_kompleks') {
                $bankSoal->pernyataanKompleks()->delete();
            }

            // jika PG Kompleks → simpan pernyataan baru
            if ($request->tipe === 'pg_kompleks') {
                $bankSoal->pernyataanKompleks()->delete();
                OpsiJawaban::where('soal_id', $bankSoal->id)->delete();

                foreach ($request->pernyataan as $index => $item) {
                    BankSoalPernyataan::create([
                        'banksoal_id'   => $bankSoal->id,
                        'urutan'        => $index + 1,
                        'teks'          => $item['text'],
                        'jawaban_benar' => $item['jawaban'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Soal berhasil diperbarui'
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // 🔹 DELETE /api/bank-soal/{id}
    public function destroy($id)
    {
        $bankSoal = BankSoal::findOrFail($id);
        $bankSoal->delete();

        return response()->json([
            'message' => 'Bank soal berhasil dihapus'
        ]);
    }
}