<?php

namespace App\Http\Controllers;

use App\Models\CfProblem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CfProblemController extends Controller
{
    public function index(): JsonResponse
    {
        $problems = CfProblem::with('mapel')->orderBy('created_at', 'desc')->get();
        return response()->json([
            'success' => true,
            'data' => $problems
        ]);
    }

    public function store(Request $request, \App\Services\CodeforcesService $codeforces): JsonResponse
    {
        $validated = $request->validate([
            'mapel_id' => 'required|exists:mapel,id',
            'cf_contest_id' => 'required|integer',
            'cf_index' => 'required|string',
            'name' => 'required|string',
            'tags' => 'nullable|array',
            'rating' => 'nullable|integer',
            'points' => 'required|integer|min:0'
        ]);
        
        $htmlFetched = false;
        try {
            $statementHtml = $codeforces->getProblemStatementHtml($validated['cf_contest_id'], $validated['cf_index']);
            if ($statementHtml) {
                $validated['statement_html'] = $statementHtml;
                $htmlFetched = true;
            }
        } catch (\Throwable $e) {}

        $problem = CfProblem::updateOrCreate(
            [
                'mapel_id' => $validated['mapel_id'],
                'cf_contest_id' => $validated['cf_contest_id'],
                'cf_index' => $validated['cf_index'],
            ],
            $validated
        );

        $message = 'Problem Codeforces berhasil disimpan.';
        if (!$htmlFetched) {
            $message = 'Problem disimpan, tapi Gagal mengambil deskripsi HTML otomatis karena diblokir Cloudflare Codeforces (Anti-Bot). Anda dapat mencoba pratinjau nanti.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $problem
        ]);
    }

    public function update(Request $request, CfProblem $cfProblem): JsonResponse
    {
        $validated = $request->validate([
            'statement_html' => 'nullable|string',
            'mapel_id' => 'nullable|exists:mapel,id',
            'points' => 'nullable|integer|min:0',
        ]);

        $cfProblem->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Problem Codeforces berhasil diperbarui.',
            'data' => $cfProblem
        ]);
    }

    public function destroy(CfProblem $cfProblem): JsonResponse
    {
        $cfProblem->delete();
        return response()->json([
            'success' => true,
            'message' => 'Problem Codeforces berhasil dihapus.'
        ]);
    }

    public function statement(CfProblem $cfProblem, \App\Services\CodeforcesService $codeforces): JsonResponse
    {
        try {
            $html = $cfProblem->statement_html;
            
            if (empty($html)) {
                $html = $codeforces->getProblemStatementHtml($cfProblem->cf_contest_id, $cfProblem->cf_index);
                if ($html) {
                    $cfProblem->statement_html = $html;
                    $cfProblem->save();
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal mengambil deskripsi soal (Terblokir Cloudflare)'
                    ], 500);
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $html
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil deskripsi soal: ' . $e->getMessage()
            ], 500);
        }
    }
}
