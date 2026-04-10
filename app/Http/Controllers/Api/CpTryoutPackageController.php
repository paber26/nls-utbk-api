<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CpTryoutPackage;
use App\Models\User;
use App\Models\CpSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CpTryoutPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = CpTryoutPackage::query()
            ->with('creator:id,name')
            ->withCount('problems')
            ->latest()
            ->get()
            ->map(function (CpTryoutPackage $package) {
                return [
                    'id' => $package->id,
                    'nama_paket' => $package->nama_paket,
                    'durasi_menit' => (int) $package->durasi_menit,
                    'mulai' => $package->mulai,
                    'selesai' => $package->selesai,
                    'status' => $package->status,
                    'jumlah_soal' => (int) $package->problems_count,
                    'pembuat' => $package->creator?->name ?? '-',
                    'created_at' => $package->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama_paket' => 'required|string|max:255',
            'durasi_menit' => 'required|integer|min:1|max:1440',
            'mulai' => 'nullable|date',
            'selesai' => 'nullable|date|after_or_equal:mulai',
            'status' => 'nullable|in:draft,active,finished',
        ]);

        $package = CpTryoutPackage::create([
            'nama_paket' => $validated['nama_paket'],
            'durasi_menit' => $validated['durasi_menit'],
            'mulai' => $validated['mulai'] ?? null,
            'selesai' => $validated['selesai'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paket tryout CP berhasil dibuat.',
            'data' => $this->buildPackageDetail($package->id),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->buildPackageDetail($id),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'nama_paket' => 'required|string|max:255',
            'durasi_menit' => 'required|integer|min:1|max:1440',
            'mulai' => 'nullable|date',
            'selesai' => 'nullable|date|after_or_equal:mulai',
            'status' => 'required|in:draft,active,finished',
        ]);

        $package = CpTryoutPackage::findOrFail($id);
        $package->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Paket tryout CP berhasil diperbarui.',
            'data' => $this->buildPackageDetail($package->id),
        ]);
    }

    public function syncProblems(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'problem_ids' => 'required|array',
            'problem_ids.*' => 'required|integer|exists:cp_problems,id',
        ]);

        $package = CpTryoutPackage::findOrFail($id);
        $problemIds = collect($validated['problem_ids'])
            ->values()
            ->map(fn ($problemId) => (string) $problemId);

        $syncPayload = $problemIds
            ->mapWithKeys(fn ($problemId, $index) => [
                $problemId => ['urutan' => $index + 1],
            ])
            ->all();

        $package->problems()->sync($syncPayload);

        return response()->json([
            'success' => true,
            'message' => 'Daftar soal paket berhasil diperbarui.',
            'data' => $this->buildPackageDetail($package->id),
        ]);
    }

    public function leaderboard(Request $request, int $id): JsonResponse
    {
        $package = CpTryoutPackage::with(['problems' => function ($q) {
            $q->select('cp_problems.id', 'title', 'points');
        }])->findOrFail($id);

        $problemIds = $package->problems->pluck('id')->values();
        if ($problemIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'paket' => $this->buildPackageSummary($package),
                    'leaderboard' => [],
                ],
            ]);
        }

        $includeAll = filter_var($request->query('include_all', false), FILTER_VALIDATE_BOOLEAN);

        $participantsQuery = User::query()
            ->select('id', 'name', 'nama_lengkap', 'sekolah_nama')
            ->where(function ($query) {
                $query->whereNull('role')->orWhere('role', '<>', 'admin');
            });

        if (!$includeAll) {
            $participantsQuery->whereHas('cpSubmissions', function ($q) use ($problemIds) {
                $q->whereIn('problem_id', $problemIds);
            });
        }

        $participants = $participantsQuery->orderBy('nama_lengkap')->get();
        if ($participants->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'paket' => $this->buildPackageSummary($package),
                    'leaderboard' => [],
                ],
            ]);
        }

        $participantIds = $participants->pluck('id');

        $submissionByProblem = CpSubmission::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('problem_id', $problemIds)
            ->select([
                'user_id',
                'problem_id',
                DB::raw('MAX(CASE WHEN verdict = "Accepted" THEN 100 ELSE 0 END) AS best_points'), // Adjust scoring explicitly
                DB::raw('MAX(created_at) AS last_submission_at'),
                DB::raw('COUNT(*) AS attempt_count'),
            ])
            ->groupBy('user_id', 'problem_id')
            ->get()
            ->groupBy('user_id');

        $rows = $participants->map(function (User $user) use ($submissionByProblem) {
            $entries = $submissionByProblem->get($user->id, collect());
            $solvedCount = $entries->filter(fn ($row) => (int) $row->best_points > 0)->count();
            $totalPoints = (int) $entries->sum(fn ($row) => (int) $row->best_points);
            $attemptedProblems = $entries->count();
            $lastSubmissionAt = $entries
                ->pluck('last_submission_at')
                ->filter()
                ->max();

            return [
                'user_id' => $user->id,
                'name' => $user->nama_lengkap ?: $user->name,
                'school' => $user->sekolah_nama ?: '-',
                'total_points' => $totalPoints,
                'solved_count' => $solvedCount,
                'attempted_count' => $attemptedProblems,
                'status_pengerjaan' => $attemptedProblems > 0 ? 'sudah_mengerjakan' : 'belum_mengerjakan',
                'last_submission_at' => $lastSubmissionAt,
            ];
        })->all();

        usort($rows, function (array $a, array $b) {
            if ($a['total_points'] !== $b['total_points']) {
                return $b['total_points'] <=> $a['total_points'];
            }

            if ($a['solved_count'] !== $b['solved_count']) {
                return $b['solved_count'] <=> $a['solved_count'];
            }

            return strcmp((string) ($a['last_submission_at'] ?? ''), (string) ($b['last_submission_at'] ?? ''));
        });

        $rankedRows = collect($rows)->values()->map(function (array $item, int $index) {
            $item['rank'] = $index + 1;
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'paket' => $this->buildPackageSummary($package),
                'leaderboard' => $rankedRows,
            ],
        ]);
    }

    public function submissionsList(Request $request, int $id): JsonResponse
    {
        $package = CpTryoutPackage::findOrFail($id);
        $problemIds = $package->problems()->pluck('cp_problems.id');

        $submissions = CpSubmission::whereIn('problem_id', $problemIds)
            ->with(['user:id,name,nama_lengkap', 'problem:id,title'])
            ->orderByDesc('created_at')
            ->get();

        $languages = [
            54 => 'C++',
            71 => 'Python',
            62 => 'Java'
        ];

        $data = $submissions->map(function ($sub) use ($languages) {
            return [
                'id' => $sub->id,
                'user' => $sub->user->nama_lengkap ?: $sub->user->name,
                'problem_title' => $sub->problem->title ?? 'Unknown',
                'language' => $languages[$sub->language_id] ?? 'Unknown',
                'verdict' => $sub->verdict,
                'execution_time' => $sub->execution_time,
                'memory_used' => $sub->memory_used,
                'created_at' => $sub->created_at->format('Y-m-d H:i:s'),
                'time_ago' => $sub->created_at->diffForHumans()
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'paket' => $this->buildPackageSummary($package),
                'submissions' => $data,
            ],
        ]);
    }

    private function buildPackageDetail(int $id): array
    {
        $package = CpTryoutPackage::with([
            'creator:id,name',
            'problems' => function ($q) {
                $q->select(
                    'cp_problems.id',
                    'cp_problems.komponen_id',
                    'cp_problems.title',
                    'cp_problems.points'
                );
            },
        ])->findOrFail($id);

        return [
            'id' => $package->id,
            'nama_paket' => $package->nama_paket,
            'durasi_menit' => (int) $package->durasi_menit,
            'mulai' => $package->mulai,
            'selesai' => $package->selesai,
            'status' => $package->status,
            'pembuat' => $package->creator?->name ?? '-',
            'soal' => $package->problems->map(function ($problem) {
                return [
                    'id' => $problem->id,
                    'title' => $problem->title,
                    'points' => (int) ($problem->points ?? 0),
                    'urutan' => (int) ($problem->pivot?->urutan ?? 0),
                ];
            })->values(),
        ];
    }

    private function buildPackageSummary(CpTryoutPackage $package): array
    {
        return [
            'id' => $package->id,
            'nama_paket' => $package->nama_paket,
            'durasi_menit' => (int) $package->durasi_menit,
            'mulai' => $package->mulai,
            'selesai' => $package->selesai,
            'status' => $package->status,
            'jumlah_soal' => $package->problems->count(),
            'total_poin_maksimal' => (int) $package->problems->sum('points'),
        ];
    }
}
