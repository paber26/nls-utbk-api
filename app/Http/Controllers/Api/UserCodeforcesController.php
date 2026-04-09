<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CfProblem;
use App\Models\UserCfSubmission;
use App\Models\UserCpScore;
use App\Services\CodeforcesService;

class UserCodeforcesController extends Controller
{
    /**
     * Tampilkan semua daftar soal Codeforces yang tersedia untuk dikerjakan.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Dapatkan semua masalah Codeforces (Opsional: difilter berdasar mapel jika perlu)
        $problems = CfProblem::with('mapel')->orderBy('cf_contest_id', 'desc')->orderBy('cf_index', 'asc')->get();
        
        // Ambil data status submission user yang sudah solved
        $solvedIds = UserCfSubmission::where('user_id', $user->id)
            ->where('verdict', 'OK')
            ->pluck('cf_problem_id')
            ->toArray();

        $problemsData = $problems->map(function ($problem) use ($solvedIds) {
            return [
                'id' => $problem->id,
                'cf_contest_id' => $problem->cf_contest_id,
                'cf_index' => $problem->cf_index,
                'name' => $problem->name,
                'mapel' => $problem->mapel ? $problem->mapel->nama : 'Informatika',
                'tags' => $problem->tags,
                'rating' => $problem->rating,
                'points' => $problem->points,
                'is_solved' => in_array($problem->id, $solvedIds),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $problemsData
        ]);
    }

    /**
     * Tampilkan detail soal beserta deskripsi HTML-nya
     */
    public function show($id, CodeforcesService $cfService)
    {
        $problem = CfProblem::with('mapel')->findOrFail($id);
        
        // Coba untuk mendapat HTML problem statement
        try {
            $statementHtml = $cfService->getProblemStatementHtml($problem->cf_contest_id, $problem->cf_index);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $problem->id,
                    'cf_contest_id' => $problem->cf_contest_id,
                    'cf_index' => $problem->cf_index,
                    'name' => $problem->name,
                    'mapel' => $problem->mapel ? $problem->mapel->nama : 'Informatika',
                    'statement_html' => $statementHtml,
                    'is_solved' => UserCfSubmission::where('user_id', request()->user()->id)
                                    ->where('cf_problem_id', $problem->id)
                                    ->where('verdict', 'OK')
                                    ->exists()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat soal dari Codeforces: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tampilkan metadata soal saja (tanpa statement HTML yang berat)
     */
    public function info($id)
    {
        $problem = CfProblem::with('mapel')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $problem->id,
                'cf_contest_id' => $problem->cf_contest_id,
                'cf_index' => $problem->cf_index,
                'name' => $problem->name,
                'mapel' => $problem->mapel ? $problem->mapel->nama : 'Informatika',
                'tags' => $problem->tags,
                'points' => $problem->points,
                'rating' => $problem->rating,
            ]
        ]);
    }

    /**
     * Lakukan sinkronisasi ke Codeforces API untuk mengecek apakah user sudah accept soal ini
     */
    public function sync(Request $request, $id, CodeforcesService $cfService)
    {
        $user = $request->user();
        if (!$user->cf_handle) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum memasukkan Username Codeforces di Profil!'
            ], 403);
        }

        $problem = CfProblem::findOrFail($id);

        try {
            $submissions = $cfService->userStatus($user->cf_handle, 1, 20); // Ambil 20 submission terbari
            
            $solved = false;
            foreach ($submissions as $sub) {
                // Periksa apakah ini kontes dan index yang tepat
                if ($sub['problem']['contestId'] == $problem->cf_contest_id && $sub['problem']['index'] == $problem->cf_index) {
                    
                    // Simpan submission rate ke DB kita (meski gak OK agar terekod history)
                    UserCfSubmission::updateOrCreate(
                        ['cf_submission_id' => $sub['id']],
                        [
                            'user_id' => $user->id,
                            'cf_problem_id' => $problem->id,
                            'verdict' => $sub['verdict'] ?? 'UNKNOWN',
                            'points' => ($sub['verdict'] === 'OK') ? $problem->points : 0,
                        ]
                    );

                    if (isset($sub['verdict']) && $sub['verdict'] === 'OK') {
                        $solved = true;
                    }
                }
            }

            if ($solved) {
                // Update aggregate score
                $this->updateAggregateScore($user);

                return response()->json([
                    'success' => true,
                    'message' => 'Sukses! Solusi Anda telah diverifikasi benar oleh Codeforces.',
                    'verdict' => 'OK'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ditemukan riwayat submission dengan status Benar (Accepted) untuk soal ini.',
                    'verdict' => 'NOT_OK'
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi server Codeforces: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tautkan akun Codeforces ke profil user (disertai validasi ke API CF)
     */
    public function linkHandle(Request $request, CodeforcesService $cfService)
    {
        $request->validate([
            'cf_handle' => 'required|string|max:64',
        ]);

        $handle = $request->cf_handle;
        $user = $request->user();

        try {
            // Validasi apakah handle tersebut benar-benar ada di Codeforces
            $cfService->getUserInfo($handle);

            // Jika valid, simpan ke database
            $user->update(['cf_handle' => $handle]);

            // Update/Create CP Score entry
            UserCpScore::updateOrCreate(
                ['user_id' => $user->id],
                ['cf_handle' => $handle]
            );

            return response()->json([
                'success' => true,
                'message' => 'Akun Codeforces berhasil ditautkan!',
                'data' => [
                    'cf_handle' => $handle
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Ambil daftar peringkat pengerjaan CP
     */
    public function leaderboard()
    {
        $scores = UserCpScore::with('user:id,nama_lengkap,sekolah_nama,avatar')
            ->orderBy('total_points', 'desc')
            ->orderBy('solved_count', 'desc')
            ->limit(50)
            ->get();

        $data = $scores->map(function ($score, $index) {
            return [
                'rank' => $index + 1,
                'user_id' => $score->user_id,
                'name' => $score->user->nama_lengkap ?? 'Anonymous',
                'school' => $score->user->sekolah_nama ?? '-',
                'avatar' => $score->user->avatar,
                'cf_handle' => $score->cf_handle,
                'total_points' => $score->total_points,
                'solved_count' => $score->solved_count,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Helper untuk menghitung ulang total poin user
     */
    private function updateAggregateScore($user)
    {
        $stats = UserCfSubmission::where('user_id', $user->id)
            ->where('verdict', 'OK')
            ->selectRaw('SUM(points) as total_points, COUNT(DISTINCT cf_problem_id) as solved_count')
            ->first();

        UserCpScore::updateOrCreate(
            ['user_id' => $user->id],
            [
                'cf_handle' => $user->cf_handle,
                'total_points' => $stats->total_points ?? 0,
                'solved_count' => $stats->solved_count ?? 0,
            ]
        );
    }
}
