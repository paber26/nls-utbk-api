<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tryout;
use App\Models\Attempt;
use App\Models\TryoutSoal;

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
                'tryout.status'
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
    
    public function show($id)
    {
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
            ->map(function ($attempt) {
                return [
                    'id' => $attempt->id,
                    'name' => $attempt->user->name ?? '-',
                    'email' => $attempt->user->email ?? '-',
                    'whatsapp' => $attempt->user->whatsapp ?? '-',
                    'sekolah_nama' => $attempt->user->sekolah->nama ?? ($attempt->user->sekolah_nama ?? '-'),
                    'status' => $attempt->status,
                    'nilai' => $attempt->nilai,
                    'mulai' => $attempt->mulai,
                    'selesai' => $attempt->selesai,
                    'jawaban_count' => $attempt->jawaban_count ?? 0,
                    'answered_numbers' => $attempt->jawabanPeserta->isNotEmpty()
                        ? TryoutSoal::where('tryout_id', $attempt->tryout_id)
                            ->whereIn(
                                'banksoal_id',
                                $attempt->jawabanPeserta->pluck('banksoal_id')
                            )
                            ->orderBy('urutan')
                            ->pluck('urutan')
                            ->values()
                        : [],
                ];
            });

        return response()->json($participants);
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
            'status'  => 'submitted',
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
}
