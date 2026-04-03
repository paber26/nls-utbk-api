<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\Attempt;
use App\Models\TryoutSoal;
use App\Models\BankSoal;
use App\Models\JawabanPeserta;
use App\Models\OpsiJawaban;
use App\Models\BankSoalPernyataan;

class UserTryoutController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $tryouts = Tryout::where('status', 'active')
            ->withCount('questions')
            ->with([
                'attempts' => function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                },
                'komponen'
            ])
            ->get()
            ->map(function ($tryout) {
                $attempt = $tryout->attempts->first();

                if (!$attempt) {
                    $status = null;
                } else {
                    $status = $attempt->status;
                }

                $komponenFirst = $tryout->komponen->first();

                return [
                    'id' => $tryout->id,
                    'nama' => $tryout->paket ?? $tryout->nama,
                    'jenjang' => $komponenFirst->mata_uji ?? '-',
                    'komponen' => $tryout->komponen->pluck('nama_komponen')->join(', ') ?: '-',
                    'jumlah_soal' => $tryout->questions_count ?? 0,
                    'durasi' => $tryout->durasi_menit ?? $tryout->durasi,
                    'status' => $status,
                ];
            });

        return response()->json([
            'data' => $tryouts
        ]);
    }

    public function show($id)
    {
        $tryout = Tryout::where('status', 'active')
            ->withCount('questions')
            ->with('komponen')
            ->findOrFail($id);

        $komponenFirst = $tryout->komponen->first();

        return response()->json([
            'data' => [
                'id' => $tryout->id,
                'nama' => $tryout->paket ?? $tryout->nama,
                'jenjang' => $komponenFirst->mata_uji ?? '-',
                'komponen' => $tryout->komponen->pluck('nama_komponen')->join(', ') ?: '-',
                'jumlah_soal' => $tryout->questions_count ?? 0,
                'durasi' => $tryout->durasi_menit ?? $tryout->durasi,
                'mulai' => $tryout->mulai,
                'selesai' => $tryout->selesai,
                'ketentuan_khusus' => $tryout->ketentuan_khusus,
            ]
        ]);
    }

    public function start($id)
    {
        $user = Auth::user();

        $tryout = Tryout::where('status', 'active')->findOrFail($id);

        $attempt = $tryout->attempts()
            ->where('user_id', $user->id)
            ->whereNull('selesai')
            ->first();

        if (!$attempt) {
            $attempt = $tryout->attempts()->create([
                'tryout_id' => $tryout->id,
                'user_id' => $user->id,
                'mulai' => now(),
                'status' => 'ongoing',
            ]);
        }

        $activeKomponen = \App\Models\AttemptKomponen::where('attempt_id', $attempt->id)
            ->whereNull('selesai')
            ->first();

        if (!$activeKomponen) {
            $firstKomponen = $tryout->komponen()->orderBy('tryout_komponen.urutan', 'asc')->first();
            if ($firstKomponen) {
                \App\Models\AttemptKomponen::create([
                    'attempt_id' => $attempt->id,
                    'komponen_id' => $firstKomponen->id,
                    'mulai' => now(),
                    'status' => 'ongoing'
                ]);
            }
        }

        return response()->json([
            'message' => 'Tryout started',
            'attempt_id' => $attempt->id
        ]);
    }

    public function remainingTime($id)
    {
        $user = Auth::user();

        $tryout = Tryout::where('status', 'active')->findOrFail($id);

        $attempt = Attempt::where('tryout_id', $tryout->id)
            ->where('user_id', $user->id)
            ->where('status', 'ongoing')
            ->first();

        // Jika tidak ada attempt aktif (sudah submit atau belum mulai)
        if (!$attempt) {
            return response()->json([
                'mulai' => null,
                'durasi_menit' => $tryout->durasi_menit ?? 0,
                'waktu_selesai' => null,
                'sisa_detik' => 0,
                'komponen_id' => null,
            ]);
        }

        $activeKomponen = \App\Models\AttemptKomponen::with('komponen')
            ->where('attempt_id', $attempt->id)
            ->whereNull('selesai')
            ->first();

        if (!$activeKomponen) {
            return response()->json([
                'mulai' => null,
                'durasi_menit' => 0,
                'waktu_selesai' => null,
                'sisa_detik' => 0,
                'komponen_id' => null,
            ]);
        }

        $pivot = \Illuminate\Support\Facades\DB::table('tryout_komponen')
            ->where('tryout_id', $tryout->id)
            ->where('komponen_id', $activeKomponen->komponen_id)
            ->first();

        $durasiMenit = $pivot ? $pivot->durasi_menit : 0;
        $waktuSelesai = $activeKomponen->mulai->copy()->addMinutes($durasiMenit);
        $sekarang = now();

        // Hitung selisih dalam detik
        $sisaDetik = $sekarang->lessThan($waktuSelesai)
            ? $sekarang->diffInSeconds($waktuSelesai)
            : 0;

        return response()->json([
            'mulai' => $activeKomponen->mulai,
            'durasi_menit' => $durasiMenit,
            'waktu_selesai' => $waktuSelesai,
            'sisa_detik' => $sisaDetik,
            'komponen_id' => $activeKomponen->komponen_id,
            'komponen_nama' => $activeKomponen->komponen->nama_komponen ?? '-'
        ]);
    }

    public function questions($id)
    {
        $user = Auth::user();

        $attempt = Attempt::where('tryout_id', $id)
            ->where('user_id', $user->id)
            ->whereNull('selesai')
            ->firstOrFail();

        $tryoutSoalList = TryoutSoal::where('tryout_id', $id)
            ->orderBy('urutan')
            ->get();

        $totalSoal = $tryoutSoalList->count();

        $result = [];

        foreach ($tryoutSoalList as $index => $tryoutSoal) {

            $bankSoal = BankSoal::find($tryoutSoal->banksoal_id);
            if (!$bankSoal)
                continue;

            $tipe = $bankSoal->tipe;
            $opsi = null;

            if (in_array($tipe, ['pg', 'pg_majemuk'])) {
                $opsi = OpsiJawaban::where('soal_id', $bankSoal->id)
                    ->orderBy('label')
                    ->get()
                    ->map(fn($o) => [
                        'key' => $o->label,
                        'text' => $o->teks,
                    ])
                    ->values();
            }

            if ($tipe === 'pg_kompleks') {
                $opsi = BankSoalPernyataan::where('banksoal_id', $bankSoal->id)
                    ->orderBy('urutan')
                    ->get()
                    ->map(fn($p) => [
                        'key' => $p->urutan,
                        'text' => $p->teks,
                    ])
                    ->values();
            }

            $jawaban = JawabanPeserta::where('attempt_id', $attempt->id)
                ->where('banksoal_id', $bankSoal->id)
                ->value('jawaban');

            $result[] = [
                'nomor' => $index + 1,
                'pertanyaan' => $bankSoal->pertanyaan,
                'tipe' => $tipe,
                'opsi' => $opsi,
                'jawaban' => $jawaban ?? [],
                'peserta' => $user->name,
                'total_soal' => $totalSoal,
                'komponen_nama' => $bankSoal->komponen->nama_komponen ?? '-',
            ];
        }

        return response()->json([
            'data' => $result
        ]);
    }

    public function answer(Request $request, $id)
    {
        $request->validate([
            'nomor' => 'required|integer|min:1',
            'jawaban' => 'array'
        ]);

        $user = Auth::user();

        $attempt = Attempt::where('tryout_id', $id)
            ->where('user_id', $user->id)
            ->whereNull('selesai')
            ->firstOrFail();

        $tryoutSoal = TryoutSoal::where('tryout_id', $id)
            ->orderBy('urutan')
            ->skip($request->nomor - 1)
            ->firstOrFail();

        $bankSoal = BankSoal::findOrFail($tryoutSoal->banksoal_id);

        $activeKomponen = \App\Models\AttemptKomponen::where('attempt_id', $attempt->id)
            ->whereNull('selesai')
            ->first();

        if (!$activeKomponen || $activeKomponen->komponen_id !== $bankSoal->komponen_id) {
            return response()->json(['message' => 'Soal ini tidak berada pada komponen yang sedang aktif'], 400);
        }

        $isCorrect = null;
        $jawabanUser = [];

        /*
        |--------------------------------------------------------------------------
        | ISIAN
        |--------------------------------------------------------------------------
        */
        if ($bankSoal->tipe === 'isian') {
            $jawabanUser = array_values($request->jawaban ?? []);

            if (
                isset($bankSoal->jawaban) &&
                isset($jawabanUser[0]) &&
                strtolower(trim($jawabanUser[0])) === strtolower(trim($bankSoal->jawaban))
            ) {
                $isCorrect = 1;
            } else {
                $isCorrect = 0;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PG
        |--------------------------------------------------------------------------
        */
        if ($bankSoal->tipe === 'pg') {
            $jawabanUser = array_values($request->jawaban ?? []);

            if (isset($jawabanUser[0])) {
                $opsi = OpsiJawaban::where('soal_id', $bankSoal->id)
                    ->where('label', $jawabanUser[0])
                    ->first();

                $isCorrect = ($opsi && $opsi->is_correct) ? 1 : 0;
            } else {
                $isCorrect = 0;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PG MAJEMUK
        |--------------------------------------------------------------------------
        */
        if ($bankSoal->tipe === 'pg_majemuk') {

            $jawabanUser = array_values($request->jawaban ?? []);

            $opsiList = OpsiJawaban::where('soal_id', $bankSoal->id)
                ->orderBy('label')
                ->get();

            $jumlahBenar = 0;
            $totalBenar = $opsiList->where('is_correct', 1)->count();

            foreach ($opsiList as $opsi) {
                if (
                    in_array($opsi->label, $jawabanUser) &&
                    (int) $opsi->is_correct === 1
                ) {
                    $jumlahBenar++;
                }
            }

            // benar penuh jika semua jawaban benar dipilih
            $isCorrect = ($jumlahBenar === $totalBenar && count($jawabanUser) === $totalBenar) ? 1 : 0;
        }

        /*
        |--------------------------------------------------------------------------
        | PG KOMPLEKS → isi null jika tidak dijawab
        |--------------------------------------------------------------------------
        */
        if ($bankSoal->tipe === 'pg_kompleks') {

            $pernyataan = BankSoalPernyataan::where('banksoal_id', $bankSoal->id)
                ->orderBy('urutan')
                ->get();

            $answermap = $request->jawaban ?? [];
            $jumlahBenar = 0;

            foreach ($pernyataan as $index => $p) {
                $key = (string) $p->urutan;

                if (array_key_exists($key, $answermap)) {
                    $value = (int) $answermap[$key];
                    $jawabanUser[] = (string) $value;

                    if ($value === (int) $p->jawaban_benar) {
                        $jumlahBenar++;
                    }
                } else {
                    // tidak dijawab → simpan null
                    $jawabanUser[] = null;
                }
            }

            // benar penuh jika semua pernyataan benar
            $isCorrect = ($jumlahBenar === $pernyataan->count()) ? 1 : 0;
        }

        JawabanPeserta::updateOrCreate(
            [
                'attempt_id' => $attempt->id,
                'banksoal_id' => $tryoutSoal->banksoal_id,
            ],
            [
                'jawaban' => $jawabanUser,
                'is_correct' => $isCorrect,
            ]
        );

        return response()->json([
            'message' => 'Jawaban tersimpan'
        ]);
    }

    public function hasil($tryoutId)
    {
        $attempt = Attempt::with([
            'tryout',
            'jawabanPeserta'
        ])
            ->where('tryout_id', $tryoutId)
            ->where('user_id', Auth::id())
            ->where('status', 'submitted')
            ->latest('selesai')
            ->firstOrFail();

        // $jawaban = $attempt->jawabanPeserta; // REMOVED
        $nilaiPoin = 0;

        // Ambil semua soal tryout (bukan hanya yang dijawab)
        $semuaSoal = TryoutSoal::where('tryout_id', $attempt->tryout_id)
            ->orderBy('urutan')
            ->get();

        // Ambil jawaban peserta lalu key by banksoal_id
        $jawabanCollection = $attempt->jawabanPeserta->keyBy('banksoal_id');

        $benar = 0;
        $salah = 0;
        $kosong = 0;
        $navigasi = [];

        foreach ($semuaSoal as $index => $tryoutSoal) {

            $bankSoal = BankSoal::find($tryoutSoal->banksoal_id);
            if (!$bankSoal)
                continue;

            $j = $jawabanCollection->get($bankSoal->id);

            if (!$j) {
                $kosong++;
                $status = 'kosong';
                $jawabanUser = [];
            } else {
                if ($j->is_correct === null) {
                    $kosong++;
                    $status = 'kosong';
                } elseif ((int) $j->is_correct === 1) {
                    $benar++;
                    $status = 'benar';
                } else {
                    $salah++;
                    $status = 'salah';
                }

                $jawabanUser = $j->jawaban ?? [];
            }

            $poinSoal = (float) ($tryoutSoal->poin ?? 0);

            /*
            |--------------------------------------------------------------------------
            | ISIAN
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'isian' && $j && (int) $j->is_correct === 1) {
                $nilaiPoin += $poinSoal;
            }

            /*
            |--------------------------------------------------------------------------
            | PG
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'pg' && isset($jawabanUser[0])) {
                $opsi = OpsiJawaban::where('soal_id', $bankSoal->id)
                    ->where('label', $jawabanUser[0])
                    ->first();

                if ($opsi && $opsi->is_correct) {
                    $nilaiPoin += (float) ($opsi->poin ?? 0);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | PG KOMPLEKS
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'pg_kompleks') {

                $pernyataan = BankSoalPernyataan::where('banksoal_id', $bankSoal->id)
                    ->orderBy('urutan')
                    ->get();

                $jumlahBenar = 0;

                foreach ($pernyataan as $p) {
                    if (
                        isset($jawabanUser[((int) $p->urutan) - 1]) &&
                        (int) $jawabanUser[((int) $p->urutan) - 1] === (int) $p->jawaban_benar
                    ) {
                        $jumlahBenar++;
                    }
                }

                if ($jumlahBenar === 4) {
                    $nilaiPoin += 1.0;
                } elseif ($jumlahBenar === 3) {
                    $nilaiPoin += 0.6;
                } elseif ($jumlahBenar === 2) {
                    $nilaiPoin += 0.2;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | PG MAJEMUK → jumlahkan poin tiap opsi (bisa negatif)
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'pg_majemuk' && is_array($jawabanUser)) {

                $opsiList = OpsiJawaban::where('soal_id', $bankSoal->id)->get();

                foreach ($opsiList as $opsi) {

                    if (in_array($opsi->label, $jawabanUser)) {
                        $nilaiPoin += (float) ($opsi->poin ?? 0);
                    }
                }
            }

            $navigasi[] = [
                'nomor' => $index + 1,
                'status' => $status,
                'komponen' => $bankSoal->komponen->nama_komponen ?? 'Lainnya',
            ];
        }

        $skorKomponen = $this->hitungNilai($attempt);

        return response()->json([
            'paket' => $attempt->tryout->paket,
            'durasi_menit' => $attempt->tryout->durasi_menit,
            'jumlah_soal' => $semuaSoal->count(),
            'benar' => $benar,
            'salah' => $salah,
            'kosong' => $kosong,
            'navigasi' => $navigasi,
            'nilai_irt' => round($attempt->nilai, 1),
            'skor_komponen' => $skorKomponen,
            // flag untuk frontend menentukan apakah pembahasan dapat dimuat
            'show_pembahasan' => (bool) $attempt->tryout->show_pembahasan,
        ]);
    }

    /**
     * Show detailed results with pembahasan for authenticated user.
     * Similar to MonitoringTryoutController::hasilPeserta but scoped to current user.
     */
    public function pembahasan($tryoutId)
    {
        $attempt = Attempt::with([
            'user:id,name,email,sekolah_nama',
            'tryout:id,paket',
            'jawabanPeserta:id,attempt_id,banksoal_id,jawaban,is_correct'
        ])
            ->where('tryout_id', $tryoutId)
            ->where('user_id', Auth::id())
            ->where('status', 'submitted')
            ->orderByDesc('selesai')
            ->orderByDesc('mulai')
            ->firstOrFail();

        $mulai = $attempt->mulai;
        $selesai = $attempt->selesai;
        $durasiMenit = null;

        if ($mulai && $selesai) {
            $durasiMenit = $mulai->diffInMinutes($selesai);
        }

        $jawabanBySoal = $attempt->jawabanPeserta->keyBy('banksoal_id');

        $soalTryout = TryoutSoal::with([
            'banksoal' => function ($q) {
                $q->select('id', 'komponen_id', 'pertanyaan', 'pembahasan', 'jawaban', 'tipe');
            },
            'banksoal.komponen'
        ])
            ->where('tryout_id', $tryoutId)
            ->orderBy('urutan')
            ->get();

        $answers = [];
        $benar = 0;
        $salah = 0;
        $kosong = 0;
        $totalPoin = 0.0;

        foreach ($soalTryout as $index => $tryoutSoal) {
            $bankSoal = $tryoutSoal->banksoal;
            if (!$bankSoal) {
                continue;
            }

            $jawaban = $jawabanBySoal->get($bankSoal->id);

            $jawabanUserRaw = $jawaban?->jawaban ?? [];
            $jawabanUser = $this->normalizeJawabanUser($jawabanUserRaw);
            $kunciJawaban = $this->resolveKunciJawaban($bankSoal->id, $bankSoal->tipe, $bankSoal->jawaban);
            $opsi = $this->resolveOpsiSoal($bankSoal->id, $bankSoal->tipe);

            if (!$jawaban || $jawaban->is_correct === null || $this->isJawabanKosong($jawabanUserRaw)) {
                $kosong++;
            } elseif ((int) $jawaban->is_correct === 1) {
                $benar++;
            } else {
                $salah++;
            }

            $poinDiperoleh = $this->hitungPoinPerSoal(
                $bankSoal->id,
                $bankSoal->tipe,
                $jawabanUserRaw,
                (float) ($tryoutSoal->poin ?? 0),
                $bankSoal->jawaban
            );

            $totalPoin += $poinDiperoleh;

            $answers[] = [
                'id' => $bankSoal->id,
                'nomor' => $tryoutSoal->urutan ?? ($index + 1),
                'soal' => $bankSoal->pertanyaan,
                'opsi' => $opsi,
                'jawaban_user' => $jawabanUser,
                'kunci_jawaban' => $kunciJawaban,
                'is_correct' => $jawaban ? (is_null($jawaban->is_correct) ? null : ((int) $jawaban->is_correct === 1)) : null,
                'pembahasan' => $bankSoal->pembahasan,
                'poin_diperoleh' => round($poinDiperoleh, 2),
                'komponen_nama' => $bankSoal->komponen->nama_komponen ?? '-',
                'tipe' => $bankSoal->tipe,
            ];
        }

        return response()->json([
            'tryout' => [
                'id' => $attempt->tryout_id,
                'nama' => $attempt->tryout?->paket ?? '-',
            ],
            'participant' => [
                'id' => $attempt->user?->id ?? $attempt->user_id,
                'name' => $attempt->user?->name ?? '-',
                'email' => $attempt->user?->email ?? '-',
                'sekolah_nama' => $attempt->user?->sekolah_nama ?? '-',
                'nama_tryout' => $attempt->tryout?->paket ?? '-',
                'mulai' => optional($mulai)->toISOString(),
                'selesai' => optional($selesai)->toISOString(),
                'durasi_menit' => $durasiMenit,
                'nilai' => (float) ($attempt->nilai ?? round($totalPoin, 1)),
                'status' => $attempt->status,
            ],
            'answers' => $answers,
            'summary' => [
                'total_soal' => count($answers),
                'benar' => $benar,
                'salah' => $salah,
                'kosong' => $kosong,
                'total_poin' => round($totalPoin, 1),
            ]
        ]);
    }

    public function finish($id)
    {
        $user = Auth::user();

        $attempt = Attempt::where('tryout_id', $id)
            ->where('user_id', $user->id)
            ->whereNull('selesai')
            ->first();

        if (!$attempt) {
            return response()->json([
                'message' => 'Tryout sudah diakhiri atau tidak ditemukan'
            ], 400);
        }

        $this->finishLogic($attempt);

        return response()->json([
            'message' => 'Tryout berhasil diakhiri'
        ]);
    }

    public function nextKomponen($id)
    {
        $user = Auth::user();

        $attempt = Attempt::where('tryout_id', $id)
            ->where('user_id', $user->id)
            ->whereNull('selesai')
            ->firstOrFail();

        $activeKomponen = \App\Models\AttemptKomponen::where('attempt_id', $attempt->id)
            ->whereNull('selesai')
            ->first();

        if ($activeKomponen) {
            $activeKomponen->update([
                'selesai' => now(),
                'status' => 'finished'
            ]);
        }

        $tryout = Tryout::findOrFail($id);
        $allKomponen = $tryout->komponen()->orderBy('tryout_komponen.urutan', 'asc')->get();

        $nextKomponen = null;
        $foundCurrent = false;

        foreach ($allKomponen as $komponen) {
            if (!$activeKomponen) {
                // Fallback jika tidak ada active komponen sebelumnya
                $nextKomponen = $komponen;
                break;
            }

            if ($foundCurrent) {
                $nextKomponen = $komponen;
                break;
            }

            if ($komponen->id == $activeKomponen->komponen_id) {
                $foundCurrent = true;
            }
        }

        if ($nextKomponen) {
            \App\Models\AttemptKomponen::create([
                'attempt_id' => $attempt->id,
                'komponen_id' => $nextKomponen->id,
                'mulai' => now(),
                'status' => 'ongoing'
            ]);

            return response()->json([
                'message' => 'Lanjut ke komponen berikutnya',
                'komponen_id' => $nextKomponen->id,
                'is_finished' => false,
            ]);
        }

        // Kalau tidak ada komponen lagi
        $this->finishLogic($attempt);

        return response()->json([
            'message' => 'Tryout berhasil diselesaikan',
            'is_finished' => true,
        ]);
    }

    private function finishLogic($attempt)
    {
        $activeKomponen = \App\Models\AttemptKomponen::where('attempt_id', $attempt->id)
            ->whereNull('selesai')
            ->first();

        if ($activeKomponen) {
            $activeKomponen->update([
                'selesai' => now(),
                'status' => 'finished'
            ]);
        }

        $attempt->update([
            'selesai' => now(),
            'status' => 'submitted',
        ]);

        $this->hitungNilai($attempt);
    }

    public function hitungNilai($attempt)
    {
        $skorPeserta = []; // key: komponen_id
        $maxSkor = [];     // key: komponen_id

        $semuaSoal = TryoutSoal::where('tryout_id', $attempt->tryout_id)->get();
        $jawabanCollection = JawabanPeserta::where('attempt_id', $attempt->id)->get()->keyBy('banksoal_id');

        foreach ($semuaSoal as $soal) {
            $bankSoal = BankSoal::find($soal->banksoal_id);
            if (!$bankSoal)
                continue;

            $kId = $bankSoal->komponen_id;

            if (!isset($skorPeserta[$kId])) {
                $skorPeserta[$kId] = 0;
                $maxSkor[$kId] = 0;
            }

            $poinSoal = (float) ($soal->poin ?? 0);
            $poinMaxSoal = 0;

            // --- Tentukan Poin Maksimal Soal Ini ---
            if ($bankSoal->tipe === 'isian') {
                $poinMaxSoal = $poinSoal;
            } elseif ($bankSoal->tipe === 'pg') {
                $opsiBenar = OpsiJawaban::where('soal_id', $bankSoal->id)->where('is_correct', 1)->first();
                $poinMaxSoal = $opsiBenar ? (float) ($opsiBenar->poin ?? 0) : $poinSoal;
            } elseif ($bankSoal->tipe === 'pg_kompleks') {
                $poinMaxSoal = 1.0;
            } elseif ($bankSoal->tipe === 'pg_majemuk') {
                $poinMaxSoal = OpsiJawaban::where('soal_id', $bankSoal->id)->where('is_correct', 1)->sum('poin');
                if ($poinMaxSoal <= 0)
                    $poinMaxSoal = $poinSoal; // fallback
            }

            $maxSkor[$kId] += $poinMaxSoal;

            // --- Hitung Skor Peserta ---
            $jawabanRow = $jawabanCollection->get($bankSoal->id);
            if ($jawabanRow && $jawabanRow->jawaban) {
                $jawabanUser = $jawabanRow->jawaban;

                if ($bankSoal->tipe === 'isian') {
                    if (
                        isset($bankSoal->jawaban) &&
                        isset($jawabanUser[0]) &&
                        strtolower(trim($jawabanUser[0])) === strtolower(trim($bankSoal->jawaban))
                    ) {
                        $skorPeserta[$kId] += $poinSoal;
                    }
                } elseif ($bankSoal->tipe === 'pg') {
                    if (isset($jawabanUser[0])) {
                        $opsi = OpsiJawaban::where('soal_id', $bankSoal->id)
                            ->where('label', $jawabanUser[0])
                            ->first();

                        if ($opsi && $opsi->is_correct) {
                            $skorPeserta[$kId] += (float) ($opsi->poin ?? 0);
                        }
                    }
                } elseif ($bankSoal->tipe === 'pg_kompleks') {
                    $pernyataan = BankSoalPernyataan::where('banksoal_id', $bankSoal->id)
                        ->orderBy('urutan')
                        ->get();

                    $jumlahBenar = 0;
                    $totalPernyataan = $pernyataan->count();

                    foreach ($pernyataan as $p) {
                        $idx = ((int) $p->urutan) - 1;
                        if (
                            array_key_exists($idx, $jawabanUser) &&
                            $jawabanUser[$idx] !== null &&
                            (int) $jawabanUser[$idx] === (int) $p->jawaban_benar
                        ) {
                            $jumlahBenar++;
                        }
                    }

                    if ($totalPernyataan > 0) {
                        if ($jumlahBenar === $totalPernyataan) {
                            $skorPeserta[$kId] += 1.0;
                        } elseif ($jumlahBenar === $totalPernyataan - 1) {
                            $skorPeserta[$kId] += 0.6;
                        } elseif ($jumlahBenar === $totalPernyataan - 2) {
                            $skorPeserta[$kId] += 0.2;
                        }
                    }
                } elseif ($bankSoal->tipe === 'pg_majemuk') {
                    $opsiList = OpsiJawaban::where('soal_id', $bankSoal->id)->get();
                    foreach ($opsiList as $opsi) {
                        if (is_array($jawabanUser) && in_array($opsi->label, $jawabanUser)) {
                            $skorPeserta[$kId] += (float) ($opsi->poin ?? 0);
                        }
                    }
                }
            }
        }

        // --- Konversi ke Skala Dasar SNBT per Komponen ---
        $totalSemuaSkala = 0;
        $jumlahKomponen = count($maxSkor);
        $breakdown = [];
        $komponens = \App\Models\Komponen::all()->keyBy('id');

        foreach ($maxSkor as $kId => $max) {
            $peserta = $skorPeserta[$kId] ?? 0;

            if ($max > 0) {
                $ratio = min($peserta / $max, 1.0);
                $ratio = max($ratio, 0.0);
                // Rumus: 200 + ((Skor Mentah Peserta / Max Skor Mentah Komponen) * 750)
                $skalaIRT = 200 + ($ratio * 750);
            } else {
                $skalaIRT = 200;
            }

            $totalSemuaSkala += $skalaIRT;
            $namaKomponen = isset($komponens[$kId]) ? $komponens[$kId]->nama_komponen : 'Lainnya';
            $breakdown[] = [
                'nama' => $namaKomponen,
                'skor' => round($skalaIRT, 1)
            ];
        }

        // Nilai akhir attempt adalah rata-rata skala komponen
        $nilaiAkhir = $jumlahKomponen > 0 ? ($totalSemuaSkala / $jumlahKomponen) : 0;

        $attempt->update([
            'nilai' => round($nilaiAkhir, 2)
        ]);

        return $breakdown;
    }

    /* helper methods borrowed from MonitoringTryoutController */

    private function normalizeJawabanUser($jawabanUserRaw)
    {
        if (!is_array($jawabanUserRaw)) {
            return $jawabanUserRaw;
        }

        $filtered = array_values(array_filter($jawabanUserRaw, function ($v) {
            return $v !== null && $v !== '';
        }));

        if (count($filtered) === 0) {
            return null;
        }

        if (count($filtered) === 1) {
            return $filtered[0];
        }

        return $filtered;
    }

    private function isJawabanKosong($jawabanUserRaw): bool
    {
        if (!is_array($jawabanUserRaw)) {
            return $jawabanUserRaw === null || $jawabanUserRaw === '';
        }

        foreach ($jawabanUserRaw as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function resolveKunciJawaban(int $bankSoalId, string $tipe, ?string $jawabanIsian)
    {
        if ($tipe === 'isian') {
            return $jawabanIsian;
        }

        if ($tipe === 'pg') {
            return OpsiJawaban::where('soal_id', $bankSoalId)
                ->where('is_correct', 1)
                ->value('label');
        }

        if ($tipe === 'pg_majemuk') {
            return OpsiJawaban::where('soal_id', $bankSoalId)
                ->where('is_correct', 1)
                ->orderBy('label')
                ->pluck('label')
                ->values();
        }

        if ($tipe === 'pg_kompleks') {
            return BankSoalPernyataan::where('banksoal_id', $bankSoalId)
                ->orderBy('urutan')
                ->pluck('jawaban_benar')
                ->map(function ($v) {
                    return (string) $v;
                })
                ->values();
        }

        return null;
    }

    private function resolveOpsiSoal(int $bankSoalId, string $tipe)
    {
        if ($tipe === 'pg' || $tipe === 'pg_majemuk') {
            return OpsiJawaban::where('soal_id', $bankSoalId)
                ->orderBy('label')
                ->get(['label', 'teks', 'poin', 'is_correct'])
                ->map(function ($opsi) {
                    return [
                        'key' => $opsi->label,
                        'text' => $opsi->teks,
                        'poin' => (float) ($opsi->poin ?? 0),
                        'is_correct' => (bool) $opsi->is_correct,
                    ];
                })
                ->values();
        }

        if ($tipe === 'pg_kompleks') {
            return BankSoalPernyataan::where('banksoal_id', $bankSoalId)
                ->orderBy('urutan')
                ->get(['urutan', 'teks', 'jawaban_benar'])
                ->map(function ($p) {
                    return [
                        'key' => (string) $p->urutan,
                        'text' => $p->teks,
                        'kunci' => (string) $p->jawaban_benar,
                    ];
                })
                ->values();
        }

        return null;
    }

    private function hitungPoinPerSoal(int $bankSoalId, string $tipe, $jawabanUserRaw, float $poinSoal, ?string $kunciIsian = null): float
    {
        $jawabanUser = is_array($jawabanUserRaw) ? $jawabanUserRaw : [$jawabanUserRaw];

        if ($tipe === 'isian') {
            $kunci = (string) ($kunciIsian ?? '');
            $jawab = (string) ($jawabanUser[0] ?? '');

            return strtolower(trim($jawab)) === strtolower(trim($kunci)) ? $poinSoal : 0.0;
        }

        if ($tipe === 'pg') {
            $label = $jawabanUser[0] ?? null;
            if (!$label) {
                return 0.0;
            }

            $opsi = OpsiJawaban::where('soal_id', $bankSoalId)
                ->where('label', $label)
                ->first();

            return ($opsi && $opsi->is_correct) ? (float) ($opsi->poin ?? 0) : 0.0;
        }

        if ($tipe === 'pg_majemuk') {
            $opsiList = OpsiJawaban::where('soal_id', $bankSoalId)->get();
            $nilai = 0.0;

            foreach ($opsiList as $opsi) {
                if (in_array($opsi->label, $jawabanUser, true)) {
                    $nilai += (float) ($opsi->poin ?? 0);
                }
            }

            return $nilai;
        }

        if ($tipe === 'pg_kompleks') {
            $pernyataan = BankSoalPernyataan::where('banksoal_id', $bankSoalId)
                ->orderBy('urutan')
                ->get();

            $jumlahBenar = 0;
            $totalPernyataan = $pernyataan->count();

            foreach ($pernyataan as $p) {
                $index = ((int) $p->urutan) - 1;

                if (
                    array_key_exists($index, $jawabanUser) &&
                    $jawabanUser[$index] !== null &&
                    (int) $jawabanUser[$index] === (int) $p->jawaban_benar
                ) {
                    $jumlahBenar++;
                }
            }

            if ($jumlahBenar === $totalPernyataan) {
                return 1.0;
            }

            if ($jumlahBenar === $totalPernyataan - 1) {
                return 0.6;
            }

            if ($jumlahBenar === $totalPernyataan - 2) {
                return 0.2;
            }
        }

        return 0.0;
    }
}

