<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CodeforcesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class CodeforcesController extends Controller
{
    public function health(CodeforcesService $codeforces): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Koneksi ke Codeforces berhasil.',
                'data' => array_merge($codeforces->health(), [
                    'checked_at' => now()->toIso8601String(),
                ]),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }
    }

    public function userInfo(string $handle, CodeforcesService $codeforces): JsonResponse
    {
        try {
            $normalizedHandle = $this->validateHandle($handle);

            return response()->json([
                'success' => true,
                'message' => 'Profil Codeforces berhasil diambil.',
                'data' => $codeforces->getUserInfo($normalizedHandle),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }
    }

    public function userStatus(Request $request, string $handle, CodeforcesService $codeforces): JsonResponse
    {
        try {
            $validated = $request->validate([
                'count' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $normalizedHandle = $this->validateHandle($handle);

            return response()->json([
                'success' => true,
                'message' => 'Riwayat submission Codeforces berhasil diambil.',
                'data' => $codeforces->getUserStatus($normalizedHandle, (int) ($validated['count'] ?? 20)),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }
    }

    public function problemByUrl(Request $request, CodeforcesService $codeforces): JsonResponse
    {
        try {
            $validated = $request->validate([
                'url' => ['required', 'string', 'max:255'],
            ]);

            [$contestId, $index] = $this->parseProblemUrl($validated['url']);

            return response()->json([
                'success' => true,
                'message' => "Problem {$contestId}{$index} berhasil diambil.",
                'data' => $codeforces->getProblemByContestAndIndex($contestId, $index),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }
    }

    private function validateHandle(string $handle): string
    {
        $handle = trim($handle);

        if ($handle === '' || !preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $handle)) {
            throw ValidationException::withMessages([
                'handle' => 'Handle Codeforces tidak valid.',
            ]);
        }

        return $handle;
    }

    private function parseProblemUrl(string $url): array
    {
        $url = trim($url);

        $patterns = [
            '#^https?://codeforces\.com/problemset/problem/(\d+)/([A-Za-z0-9]+)$#i',
            '#^https?://codeforces\.com/contest/(\d+)/problem/([A-Za-z0-9]+)$#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return [(int) $matches[1], strtoupper($matches[2])];
            }
        }

        throw ValidationException::withMessages([
            'url' => 'URL problem Codeforces tidak valid. Gunakan format https://codeforces.com/problemset/problem/2211/C1',
        ]);
    }
}
