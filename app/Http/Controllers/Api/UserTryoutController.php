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

                if (! $attempt) {
                    $status = null;
                } else {
                    $status = $attempt->status;
                }

                return [
                    'id' => $tryout->id,
                    'nama' => $tryout->paket ?? $tryout->nama,
                    'jenjang' => $tryout->komponen->mata_uji ?? '-',
                    'komponen' => $tryout->komponen->nama_komponen ?? '-',
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

        return response()->json([
            'data' => [
                'id' => $tryout->id,
                'nama' => $tryout->paket ?? $tryout->nama,
                'jenjang' => $tryout->komponen->mata_uji ?? '-',
                'komponen' => $tryout->komponen->nama_komponen ?? '-',
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

        if (! $attempt) {
            $attempt = $tryout->attempts()->create([
                'tryout_id' => $tryout->id,
                'user_id' => $user->id,
                'mulai' => now(),
                'status' => 'ongoing',
            ]);
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
        if (! $attempt) {
            return response()->json([
                'mulai'         => null,
                'durasi_menit'  => $tryout->durasi_menit ?? 0,
                'waktu_selesai' => null,
                'sisa_detik'    => 0,
            ]);
        }

        $durasiMenit = $tryout->durasi_menit ?? 0;

        $waktuSelesai = $attempt->mulai->copy()->addMinutes($durasiMenit);

        $sekarang = now();

        // Hitung selisih dalam detik
        $sisaDetik = $sekarang->lessThan($waktuSelesai)
            ? $sekarang->diffInSeconds($waktuSelesai)
            : 0;

        return response()->json([
            'mulai'          => $attempt->mulai,
            'durasi_menit'   => $durasiMenit,
            'waktu_selesai'  => $waktuSelesai,
            'sisa_detik'     => $sisaDetik,
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
            if (! $bankSoal) continue;

            $tipe = $bankSoal->tipe;
            $opsi = null;

            if (in_array($tipe, ['pg', 'pg_majemuk'])) {
                $opsi = OpsiJawaban::where('soal_id', $bankSoal->id)
                    ->orderBy('label')
                    ->get()
                    ->map(fn($o) => [
                        'key'  => $o->label,
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
                'nomor'       => $index + 1,
                'pertanyaan'  => $bankSoal->pertanyaan,
                'tipe'        => $tipe,
                'opsi'        => $opsi,
                'jawaban'     => $jawaban ?? [],
                'peserta'     => $user->name,
                'total_soal'  => $totalSoal,
            ];
        }

        return response()->json([
            'data' => $result
        ]);
    }

    public function answer(Request $request, $id)
    {
        $request->validate([
            'nomor'   => 'required|integer|min:1',
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
                'attempt_id'  => $attempt->id,
                'banksoal_id' => $tryoutSoal->banksoal_id,
            ],
            [
                'jawaban'    => $jawabanUser,
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
            if (! $bankSoal) continue;

            $j = $jawabanCollection->get($bankSoal->id);

            if (! $j) {
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
                'nomor'  => $index + 1,
                'status' => $status,
            ];
        }

        return response()->json([
            'paket'            => $attempt->tryout->paket,
            'durasi_menit'     => $attempt->tryout->durasi_menit,
            'jumlah_soal'      => $semuaSoal->count(),
            'benar'            => $benar,
            'salah'            => $salah,
            'kosong'           => $kosong,
            'navigasi'         => $navigasi,
            'nilai_poin'       => round($nilaiPoin, 1),
            // flag untuk frontend menentukan apakah pembahasan dapat dimuat
            'show_pembahasan'  => (bool) $attempt->tryout->show_pembahasan,
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

        $soalTryout = TryoutSoal::with(['banksoal' => function ($q) {
                $q->select('id', 'pertanyaan', 'pembahasan', 'jawaban', 'tipe');
            }])
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
            if (! $bankSoal) {
                continue;
            }

            $jawaban = $jawabanBySoal->get($bankSoal->id);

            $jawabanUserRaw = $jawaban?->jawaban ?? [];
            $jawabanUser = $this->normalizeJawabanUser($jawabanUserRaw);
            $kunciJawaban = $this->resolveKunciJawaban($bankSoal->id, $bankSoal->tipe, $bankSoal->jawaban);
            $opsi = $this->resolveOpsiSoal($bankSoal->id, $bankSoal->tipe);

            if (! $jawaban || $jawaban->is_correct === null || $this->isJawabanKosong($jawabanUserRaw)) {
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

        // 1. Ambil attempt aktif
        $attempt = Attempt::where('tryout_id', $id)
            ->where('user_id', $user->id)
            ->whereNull('selesai')
            ->first();

        if (! $attempt) {
            return response()->json([
                'message' => 'Tryout sudah diakhiri atau tidak ditemukan'
            ], 400);
        }

        // 2. Kunci attempt
        $attempt->update([
            'selesai' => now(),
            'status'  => 'submitted', // opsional, tapi disarankan
        ]);

        // 3. (OPSIONAL) Hitung nilai
        $this->hitungNilai($attempt);

        return response()->json([
            'message' => 'Tryout berhasil diakhiri'
        ]);
    }

    public function hitungNilai($attempt)
    {
        $totalPoin = 0;
        
        $jawabanPeserta = JawabanPeserta::where('attempt_id', $attempt->id)->get();

        foreach ($jawabanPeserta as $jawaban) {
            $bankSoal = BankSoal::find($jawaban->banksoal_id);
            if (! $bankSoal) continue;

            // ambil tryout_soal untuk poin
            $tryoutSoal = TryoutSoal::where('tryout_id', $attempt->tryout_id)
                ->where('banksoal_id', $bankSoal->id)
                ->first();

            $poinSoal = (float) ($tryoutSoal->poin ?? 0);

            $jawabanUser = $jawaban->jawaban;

            /*
            |--------------------------------------------------------------------------
            | ISIAN → pakai tryout_soal.poin
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'isian') {
                if (
                    isset($bankSoal->jawaban) &&
                    isset($jawabanUser[0]) &&
                    strtolower(trim($jawabanUser[0])) === strtolower(trim($bankSoal->jawaban))
                ) {
                    $totalPoin += $poinSoal;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | PILIHAN GANDA BIASA → pakai opsi_jawaban.poin
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'pg') {
                if (isset($jawabanUser[0])) {
                    $opsi = OpsiJawaban::where('soal_id', $bankSoal->id)
                        ->where('label', $jawabanUser[0])
                        ->first();

                    if ($opsi && $opsi->is_correct) {
                        $totalPoin += (float) ($opsi->poin ?? 0);
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | PILIHAN GANDA KOMPLEKS (aturan nasional)
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'pg_kompleks') {
                $pernyataan = BankSoalPernyataan::where('banksoal_id', $bankSoal->id)
                    ->orderBy('urutan')
                    ->get();

                $jumlahBenar = 0;
                $totalPernyataan = $pernyataan->count();

                foreach ($pernyataan as $p) {
                    $index = ((int) $p->urutan) - 1; // karena array 0-based

                    if (
                        array_key_exists($index, $jawabanUser) &&
                        $jawabanUser[$index] !== null &&
                        (int) $jawabanUser[$index] === (int) $p->jawaban_benar
                    ) {
                        $jumlahBenar++;
                    }
                }

                // aturan nasional dinamis berdasarkan total pernyataan
                if ($jumlahBenar === $totalPernyataan) {
                    $totalPoin += 1.0;
                } elseif ($jumlahBenar === $totalPernyataan - 1) {
                    $totalPoin += 0.6;
                } elseif ($jumlahBenar === $totalPernyataan - 2) {
                    $totalPoin += 0.2;
                }
            }

                /*
            |--------------------------------------------------------------------------
            | PILIHAN GANDA MAJEMUK → jumlahkan poin tiap opsi yang dipilih (bisa negatif)
            |--------------------------------------------------------------------------
            */
            if ($bankSoal->tipe === 'pg_majemuk') {

                $opsiList = OpsiJawaban::where('soal_id', $bankSoal->id)->get();
                $jawabanUser = $jawabanUser ?? [];

                foreach ($opsiList as $opsi) {

                    if (in_array($opsi->label, $jawabanUser)) {

                        // tambahkan poin sesuai nilai di database
                        // (bisa positif atau negatif)
                        $totalPoin += (float) ($opsi->poin ?? 0);
                    }
                }
            }
        }
        
        $attempt->update([
                'nilai' => $totalPoin
            ]);
    }

    /* helper methods borrowed from MonitoringTryoutController */

    private function normalizeJawabanUser($jawabanUserRaw)
    {
        if (! is_array($jawabanUserRaw)) {
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
        if (! is_array($jawabanUserRaw)) {
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
            if (! $label) {
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

