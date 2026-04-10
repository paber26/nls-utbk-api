<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CpProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CpProblemController extends Controller
{
    public function index()
    {
        $problems = CpProblem::with(['testCases'])->withCount('testCases')->get();
        return response()->json(['success' => true, 'data' => $problems]);
    }

    public function show($id)
    {
        $problem = CpProblem::with('testCases')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $problem]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description_html' => 'nullable|string',
            'input_format_html' => 'nullable|string',
            'output_format_html' => 'nullable|string',
            'time_limit' => 'numeric|min:0.1',
            'memory_limit' => 'numeric|min:16',
            'points' => 'integer|min:0',
            'komponen_id' => 'nullable|integer|exists:komponen,id',
            'test_cases' => 'nullable|array',
            'test_cases.*.input' => 'nullable|string',
            'test_cases.*.expected_output' => 'nullable|string',
            'test_cases.*.is_hidden' => 'boolean'
        ]);

        DB::beginTransaction();
        try {
            $problem = CpProblem::create($request->only([
                'title', 'description_html', 'input_format_html', 'output_format_html', 'time_limit', 'memory_limit', 'points', 'komponen_id'
            ]));

            if ($request->has('test_cases')) {
                foreach ($request->test_cases as $tc) {
                    $problem->testCases()->create($tc);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Soal CP berhasil dibuat', 'data' => $problem], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal membuat soal', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $problem = CpProblem::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:255',
            'description_html' => 'nullable|string',
            'input_format_html' => 'nullable|string',
            'output_format_html' => 'nullable|string',
            'time_limit' => 'numeric|min:0.1',
            'memory_limit' => 'numeric|min:16',
            'points' => 'integer|min:0',
            'komponen_id' => 'nullable|integer|exists:komponen,id',
            'test_cases' => 'nullable|array'
        ]);

        DB::beginTransaction();
        try {
            $problem->update($request->only([
                'title', 'description_html', 'input_format_html', 'output_format_html', 'time_limit', 'memory_limit', 'points', 'komponen_id'
            ]));

            if ($request->has('test_cases')) {
                // To keep it simple, delete all old ones and recreate
                $problem->testCases()->delete();
                foreach ($request->test_cases as $tc) {
                    $problem->testCases()->create([
                        'input' => $tc['input'] ?? '',
                        'expected_output' => $tc['expected_output'] ?? '',
                        'is_hidden' => $tc['is_hidden'] ?? true,
                    ]);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Soal CP berhasil diperbarui', 'data' => $problem]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal memperbarui soal', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $problem = CpProblem::findOrFail($id);
        $problem->delete();
        return response()->json(['message' => 'Soal CP berhasil dihapus']);
    }
}
