<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CpProblem;
use App\Models\CpSubmission;
use App\Models\CpTryoutPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CpSubmissionController extends Controller
{
    public function packages()
    {
        $packages = CpTryoutPackage::query()
            ->withCount('problems')
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($package) {
                return [
                    'id' => $package->id,
                    'nama_paket' => $package->nama_paket,
                    'durasi_menit' => (int) $package->durasi_menit,
                    'jumlah_soal' => (int) $package->problems_count,
                    'mulai' => $package->mulai,
                    'selesai' => $package->selesai,
                    'status' => $package->status,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function packageProblems(Request $request, $packageId)
    {
        $user = $request->user();

        $package = CpTryoutPackage::query()
            ->where('status', 'active')
            ->with(['problems' => function ($query) {
                $query->orderBy('cp_tryout_package_problems.urutan');
            }])
            ->findOrFail($packageId);

        $problemIds = $package->problems->pluck('id')->all();

        $solvedIds = [];
        if (!empty($problemIds)) {
            $solvedIds = CpSubmission::where('user_id', $user->id)
                ->where('verdict', 'Accepted')
                ->whereIn('problem_id', $problemIds)
                ->pluck('problem_id')
                ->toArray();
        }

        $problemsData = $package->problems->map(function ($problem) use ($solvedIds) {
            return [
                'id' => $problem->id,
                'title' => $problem->title,
                'points' => $problem->points,
                'is_solved' => in_array($problem->id, $solvedIds),
                'urutan' => (int) ($problem->pivot?->urutan ?? 0),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'package' => [
                    'id' => $package->id,
                    'nama_paket' => $package->nama_paket,
                    'durasi_menit' => (int) $package->durasi_menit,
                    'jumlah_soal' => (int) $package->problems->count(),
                ],
                'problems' => $problemsData,
            ]
        ]);
    }

    public function getProblem($id)
    {
        // Get problem with all test cases (as requested by user to display input/output)
        $problem = CpProblem::with('testCases')->findOrFail($id);

        return response()->json(['data' => $problem]);
    }

    public function submitCode(Request $request, $problemId)
    {
        $request->validate([
            'source_code' => 'required|string',
            'language_id' => 'required|integer', // e.g. 54 for C++, 71 for Python
        ]);

        $problem = CpProblem::with('testCases')->findOrFail($problemId);
        $user = $request->user();

        // Prepare submissions for Judge0 Batch Submission
        $submissions = [];
        $testCases = $problem->testCases;

        if ($testCases->isEmpty()) {
            return response()->json(['message' => 'Soal ini belum memiliki test case.'], 400);
        }

        foreach ($testCases as $tc) {
            $submissions[] = [
                'language_id' => $request->language_id,
                'source_code' => $request->source_code,
                'stdin' => $tc->input,
                'expected_output' => $tc->expected_output,
                'cpu_time_limit' => $problem->time_limit,
                'memory_limit' => $problem->memory_limit * 1024, // Judge0 memory limit is in kilobytes
            ];
        }

        $judge0Url = env('JUDGE0_URL', 'http://103.226.138.163:2358');
        
        // 1. Send to Judge0 (batch submission)
        try {
            $response = Http::post("$judge0Url/submissions/batch?base64_encoded=false", [
                'submissions' => $submissions
            ]);

            if (!$response->successful()) {
                throw new \Exception('Gagal terhubung ke Judge0 server: ' . $response->body());
            }

            $tokensResponse = $response->json();
            $tokens = implode(',', array_column($tokensResponse, 'token'));

            // 2. Poll for results (since this is synchronous ide, we poll for maximum a few seconds)
            // For production, webhooks are better. For simplicity of MVP, we poll.
            $maxRetries = 15;
            $delayMs = 1000;
            $allFinished = false;
            $finalVerdict = 'Accepted';
            $maxTime = 0.0;
            $maxMemory = 0.0;
            $failedResponse = null;

            for ($i = 0; $i < $maxRetries; $i++) {
                // Sleep
                usleep($delayMs * 1000);

                $resCheck = Http::get("$judge0Url/submissions/batch", [
                    'tokens' => $tokens,
                    'base64_encoded' => 'false',
                    'fields' => 'token,status,time,memory,stderr,compile_output'
                ]);

                $results = $resCheck->json('submissions');
                $pending = false;

                foreach ($results as $res) {
                    $statusId = $res['status']['id'] ?? 1;
                    if (in_array($statusId, [1, 2])) { // In Queue or Processing
                        $pending = true;
                        break;
                    }
                }

                if (!$pending) {
                    $allFinished = true;
                    // Evaluate results
                    foreach ($results as $res) {
                        $time = floatval($res['time'] ?? 0);
                        $mem = floatval($res['memory'] ?? 0);
                        if ($time > $maxTime) $maxTime = $time;
                        if ($mem > $maxMemory) $maxMemory = $mem;

                        $statusId = $res['status']['id'];
                        // If any testcase failed, final verdict changes
                        if ($statusId !== 3 && $finalVerdict === 'Accepted') { 
                            $finalVerdict = $res['status']['description'];
                            $failedResponse = $res;
                        }
                    }
                    break;
                }
            }

            if (!$allFinished) {
                $finalVerdict = 'Time Limit Exceeded'; // Timeout in polling
            }

            // Save submission to database
            $submission = CpSubmission::create([
                'user_id' => $user->id,
                'problem_id' => $problem->id,
                'source_code' => $request->source_code,
                'language_id' => $request->language_id,
                'verdict' => $finalVerdict,
                'execution_time' => $maxTime,
                'memory_used' => $maxMemory,
                'judge0_response' => $allFinished ? json_encode($results) : null
            ]);

            return response()->json([
                'message' => 'Submission Selesai',
                'verdict' => $finalVerdict,
                'execution_time' => $maxTime,
                'memory_used' => $maxMemory,
                'error_detail' => $failedResponse['stderr'] ?? $failedResponse['compile_output'] ?? null
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Submission Gagal (Judge0 Offline/Error)',
                'verdict' => 'System Error',
                'execution_time' => 0,
                'memory_used' => 0,
                'error_detail' => $e->getMessage()
            ], 200);
        }
    }
}
