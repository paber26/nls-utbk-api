<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\Attempt;
use App\Models\TryoutSoal;
use App\Models\BankSoalPernyataan;
use App\Models\OpsiJawaban;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MonitoringTryoutController extends Controller
{
    public function index()
    {
        // return 'oke';
        $tryouts = Tryout::select(
            'tryout.id',
            'tryout.paket',
            'tryout.mulai',
            'tryout.selesai',
            'tryout.status',
            'tryout.show_pembahasan'
        )
            ->withCount([
                'attempts as total_peserta',
                'attempts as sedang_mengerjakan' => function ($q) {
                    $q->where('status', 'ongoing');
                },
                'attempts as sudah_selesai' => function ($q) {
                    $q->where('status', 'submitted');
                }
            ])
            ->orderByDesc('tryout.created_at')
            ->get();
        return response()->json($tryouts);
    }

    public function updatePembahasanVisibility(Request $request, $tryoutId)
    {
        if (Auth::user()?->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $data = $request->validate([
            'show_pembahasan' => 'required|boolean',
        ]);

        $tryout = Tryout::findOrFail($tryoutId);
        $tryout->update([
            'show_pembahasan' => $data['show_pembahasan'],
        ]);

        return response()->json([
            'message' => $tryout->show_pembahasan
                ? 'Pembahasan tryout berhasil ditampilkan ke user.'
                : 'Pembahasan tryout berhasil ditutup untuk user.',
            'data' => [
                'id' => $tryout->id,
                'show_pembahasan' => (bool) $tryout->show_pembahasan,
            ],
        ]);
    }

    public function show($id)
    {
        try {
            $soalInfoByBanksoal = TryoutSoal::join('banksoal', 'tryout_soal.banksoal_id', '=', 'banksoal.id')
                ->join('komponen', 'banksoal.komponen_id', '=', 'komponen.id')
                ->where('tryout_soal.tryout_id', $id)
                ->select(
                    'tryout_soal.banksoal_id',
                    'tryout_soal.urutan',
                    'komponen.nama_komponen as mapel_nama'
                )
                ->get()
                ->keyBy('banksoal_id');

            $participants = Attempt::with([
                'user:id,name,email,whatsapp,sekolah_id,sekolah_nama',
                'user.sekolah:id,nama',
                'jawabanPeserta:id,attempt_id,banksoal_id'
            ])
                ->withCount([
                    'jawabanPeserta as jawaban_count'
                ])
                ->where('tryout_id', $id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($attempt) use ($soalInfoByBanksoal) {
                    $user = $attempt->user;
                    $sekolahNama = $user?->sekolah?->nama ?? $user?->sekolah_nama ?? '-';

                    $answeredRaw = $attempt->jawabanPeserta
                        ->pluck('banksoal_id')
                        ->map(function ($banksoalId) use ($soalInfoByBanksoal) {
                            $info = $soalInfoByBanksoal->get($banksoalId);
                            if ($info) {
                                return [
                                    'num' => $info->urutan,
                                    'komponen' => $info->mapel_nama
                                ];
                            }
                            return null;
                        })
                        ->filter()
                        ->unique('num')
                        ->sortBy('num')
                        ->values();

                    $answeredNumbersGroups = $answeredRaw->groupBy('komponen')->map(function ($items, $mapel) {
                        return [
                            'komponen' => $mapel,
                            'numbers' => $items->pluck('num')->toArray(),
                        ];
                    })->values()->toArray();

                    return [
                        'id' => $attempt->id,
                        'name' => $user?->name ?? '-',
                        'email' => $user?->email ?? '-',
                        'whatsapp' => $user?->whatsapp ?? '-',
                        'sekolah_nama' => $sekolahNama,
                        'status' => $attempt->status,
                        'nilai' => $attempt->nilai,
                        'mulai' => $attempt->mulai,
                        'selesai' => $attempt->selesai,
                        'jawaban_count' => $attempt->jawaban_count ?? 0,
                        'answered_numbers' => $answeredNumbersGroups,
                    ];
                });

            return response()->json($participants);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat monitoring tryout', [
                'tryout_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Gagal memuat data monitoring tryout.',
            ], 500);
        }
    }

    public function forceFinish($attemptId)
    {
        $attempt = Attempt::where('id', $attemptId)
            ->where('status', 'ongoing')
            ->firstOrFail();

        // Ambil durasi dari tryout (dalam menit)
        $tryout = Tryout::find($attempt->tryout_id);

        $mulai = $attempt->mulai ?? now();

        // Jika ada durasi, maka selesai = mulai + durasi
        if ($tryout && $tryout->durasi) {
            $selesai = \Carbon\Carbon::parse($mulai)
                ->addMinutes($tryout->durasi);
        } else {
            $selesai = now();
        }

        $attempt->update([
            'status' => 'submitted',
            'selesai' => $selesai,
        ]);

        // Panggil fungsi hitungNilai yang sudah ada di TryoutController
        // Pastikan method hitungNilai bersifat public
        $tryoutController = app(\App\Http\Controllers\Api\UserTryoutController::class);
        $tryoutController->hitungNilai($attempt);

        return response()->json([
            'message' => 'Tryout peserta berhasil diakhiri dan nilai dihitung.',
        ]);
    }

    public function hasilPeserta($tryoutId, $participantId)
    {
        if (Auth::user()?->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $attempt = Attempt::with([
            'user:id,name,email,sekolah_nama',
            'tryout:id,paket',
            'jawabanPeserta:id,attempt_id,banksoal_id,jawaban,is_correct'
        ])
            ->where('tryout_id', $tryoutId)
            ->where(function ($q) use ($participantId) {
                // Support kedua format participantId:
                // 1) attempt.id (dari endpoint monitoring existing)
                // 2) user.id (jika frontend kirim id user)
                $q->where('id', $participantId)
                    ->orWhere('user_id', $participantId);
            })
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
                $q->select('id', 'pertanyaan', 'pembahasan', 'jawaban', 'tipe');
            }
        ])
            ->where('tryout_id', $tryoutId)
            ->orderBy('urutan')
            ->get();

        $soalInfoByBanksoal = TryoutSoal::join('banksoal', 'tryout_soal.banksoal_id', '=', 'banksoal.id')
            ->join('komponen', 'banksoal.komponen_id', '=', 'komponen.id')
            ->where('tryout_soal.tryout_id', $tryoutId)
            ->select('tryout_soal.banksoal_id', 'komponen.nama_komponen as mapel_nama')
            ->get()
            ->keyBy('banksoal_id');

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
            $komponen = $soalInfoByBanksoal->get($bankSoal->id)?->mapel_nama ?? '-';

            $answers[] = [
                'id' => $bankSoal->id,
                'nomor' => $tryoutSoal->urutan ?? ($index + 1),
                'komponen' => $komponen,
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
