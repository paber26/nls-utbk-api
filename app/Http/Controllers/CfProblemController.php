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

    public function store(Request $request): JsonResponse
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

        $problem = CfProblem::updateOrCreate(
            [
                'mapel_id' => $validated['mapel_id'],
                'cf_contest_id' => $validated['cf_contest_id'],
                'cf_index' => $validated['cf_index'],
            ],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Problem Codeforces berhasil disimpan.',
            'data' => $problem
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
}
