<?php

use Illuminate\Support\Facades\Route;
// /Users/bernaldonapitupulu/Documents/Next Level Study/nextlevelstudy-api/app/Http/Controllers/api/AuthController.php
// use App\Http\Controllers\api\AuthController;
use App\Http\Controllers\Api\WilayahController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankSoalController;
use App\Http\Controllers\Api\TryoutController;
use App\Http\Controllers\Api\TryoutSoalController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SekolahController;
use App\Http\Controllers\Api\MonitoringTryoutController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\CodeforcesController;
use App\Http\Middleware\EnsureAdminRole;

use App\Models\Sekolah;
use App\Http\Controllers\Api\UserProfilController;
use App\Http\Controllers\Api\UserTryoutController;
use App\Http\Controllers\Api\PesertaController;
use App\Http\Controllers\Api\UserCodeforcesController;
use App\Http\Controllers\Api\CpTryoutPackageController;
// use app/Http/Controllers/api/AuthController.php
use Illuminate\Http\Request;
use App\Models\Mapel;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'API connected'
    ]);
});

Route::get('/wilayah/provinsi', [WilayahController::class, 'provinsi']);
Route::get('/wilayah/kabupaten/{provinsiId}', [WilayahController::class, 'kabupaten']);
Route::get('/wilayah/kecamatan/{kabupatenId}', [WilayahController::class, 'kecamatan']);

Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    $user = $request->user();

    $profilLengkap =
        !empty($user->nama_lengkap) &&
        !empty($user->sekolah_id) &&
        !empty($user->kelas) &&
        !empty($user->provinsi) &&
        !empty($user->kota) &&
        !empty($user->kecamatan) &&
        !empty($user->whatsapp) &&
        is_array($user->minat) &&
        count($user->minat) > 0;

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'nama_lengkap' => $user->nama_lengkap,
        'sekolah_id' => $user->sekolah_id,
        'sekolah' => $user->sekolah_nama,
        'kelas' => $user->kelas,
        'email' => $user->email,
        'whatsapp' => $user->whatsapp,
        'provinsi' => $user->provinsi,
        'kota' => $user->kota,
        'kecamatan' => $user->kecamatan,
        'minat' => $user->minat,
        'avatar' => $user->avatar,
        'role' => $user->role,
        'profil_lengkap' => $profilLengkap,
        'is_event_registered' => $user->is_event_registered,
        'cf_handle' => $user->cf_handle,
    ]);
});

// Route::get('/tryout', [TryoutController::class, 'index']);

// Route::apiResource('banksoal', BankSoalController::class);
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('banksoal', BankSoalController::class);
    Route::get('/mapel', function () {
        return Mapel::select('id', 'kode', 'nama', 'tingkat')->orderBy('id')->get();
    });

    Route::get('/users', [UserController::class, 'index']);
    Route::get('/peserta', [PesertaController::class, 'index']);
    Route::get('/peserta/detail/{id}', [PesertaController::class, 'show']);
    Route::post('/peserta', [PesertaController::class, 'store']);
    Route::post('/peserta/{id}/update-password', [PesertaController::class, 'updatePassword']);
    Route::patch('/peserta/toggle-event/{id}', [PesertaController::class, 'toggleEvent']);
    Route::get('/peserta/{id}/riwayat', [PesertaController::class, 'riwayatTryout']);
    Route::delete('/peserta/{id}', [PesertaController::class, 'destroy']);
    Route::put('/users/{id}/role', [UserController::class, 'updateRole']);

    Route::middleware(EnsureAdminRole::class)->prefix('codeforces')->group(function () {
        Route::get('/health', [CodeforcesController::class, 'health']);
        Route::get('/handles/{handle}', [CodeforcesController::class, 'userInfo']);
        Route::get('/handles/{handle}/submissions', [CodeforcesController::class, 'userStatus']);
        Route::get('/problems/resolve', [CodeforcesController::class, 'problemByUrl']);
    });

    Route::middleware(EnsureAdminRole::class)->prefix('cp-problems')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\CpProblemController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\CpProblemController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Admin\CpProblemController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Admin\CpProblemController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Admin\CpProblemController::class, 'destroy']);
    });

    Route::middleware(EnsureAdminRole::class)->prefix('cp-tryout-packages')->group(function () {
        Route::get('/', [CpTryoutPackageController::class, 'index']);
        Route::post('/', [CpTryoutPackageController::class, 'store']);
        Route::get('/{id}', [CpTryoutPackageController::class, 'show']);
        Route::put('/{id}', [CpTryoutPackageController::class, 'update']);
        Route::put('/{id}/problems', [CpTryoutPackageController::class, 'syncProblems']);
        Route::get('/{id}/leaderboard', [CpTryoutPackageController::class, 'leaderboard']);
    });

    Route::get('/banksoal', [BankSoalController::class, 'index']);
    Route::get('/banksoaltryout', [BankSoalController::class, 'listForTryout']);
    // Route::get('/banksoal/tryout', [BankSoalController::class, 'listForTryout']);
    Route::post('/banksoal', [BankSoalController::class, 'store']);
    Route::get('/banksoal/{id}', [BankSoalController::class, 'show']);
    Route::put('/banksoal/{id}', [BankSoalController::class, 'update']);

    Route::get('/tryout', [TryoutController::class, 'index']);
    Route::post('/tryout', [TryoutController::class, 'store']);
    Route::get('/tryout/{id}', [TryoutController::class, 'show']);
    Route::put('/tryout/{id}', [TryoutController::class, 'update']);

    Route::get('/monitoring-tryout', [MonitoringTryoutController::class, 'index']);
    Route::get('/monitoring-tryout/{id}', [MonitoringTryoutController::class, 'show']);
    Route::get('/monitoring-tryout/{tryoutId}/peserta/{participantId}/hasil', [MonitoringTryoutController::class, 'hasilPeserta']);
    Route::post('/monitoring-tryout/{attemptId}/force-finish', [MonitoringTryoutController::class, 'forceFinish']);
    Route::patch('/monitoring-tryout/{tryoutId}/pembahasan-visibility', [MonitoringTryoutController::class, 'updatePembahasanVisibility']);
    Route::get('/leaderboard/tryouts', [LeaderboardController::class, 'tryouts']);
    Route::get('/leaderboard/{tryoutId}', [LeaderboardController::class, 'leaderboard']);

    Route::get('/tryout/{id}/soal', [TryoutSoalController::class, 'index']);
    Route::get('/tryout/{id}/soal-detail', [TryoutSoalController::class, 'indexDetail']);
    Route::post('/tryout/{id}/soal', [TryoutSoalController::class, 'store']);
    Route::delete('/tryout/{id}/soal/{banksoalId}', [TryoutSoalController::class, 'destroy']);
    Route::put('/tryout/{id}/soal/urutan', [TryoutSoalController::class, 'updateUrutan']);
    Route::put('/tryout/{id}/soal/{banksoalId}/poin', [TryoutSoalController::class, 'updatePoin']);


    Route::post('/upload-image', [UploadController::class, 'store']);


    // Route::get('/sekolah', function () {return \App\Models\Sekolah::orderBy('nama')->get();});
    // routes/api.php
    Route::get('/sekolah', [SekolahController::class, 'index']);
    Route::get('/sekolah/{id}', [SekolahController::class, 'show']);
    Route::get('/sekolah/{id}/peserta', [SekolahController::class, 'peserta']);
    Route::post('/sekolah', [SekolahController::class, 'store']);

    Route::get('/user/profile', [UserProfilController::class, 'profile']);
    Route::post('/user/profile', [UserProfilController::class, 'store']);
    Route::put('/user/profile', [UserProfilController::class, 'updateProfile']);

    Route::get('/user/tryout', [UserTryoutController::class, 'index']);
    Route::get('/user/tryout/{id}', [UserTryoutController::class, 'show']);
    Route::post('/user/tryout/{id}/start', [UserTryoutController::class, 'start']);

    Route::get('/user/tryout/{id}/remaining-time', [UserTryoutController::class, 'remainingTime']);
    Route::get('/user/tryout/{id}/questions', [UserTryoutController::class, 'questions']);

    Route::post('/user/tryout/{id}/answer', [UserTryoutController::class, 'answer']);
    Route::post('/user/tryout/{id}/finish', [UserTryoutController::class, 'finish']);
    // Route::get('/user/tryout/hasil/{tryoutId}', [UserTryoutController::class, 'hasil']);
    Route::get('/user/tryout/hasil/{tryoutId}', [UserTryoutController::class, 'hasil']);
    Route::get('/user/tryout/hasil/{tryoutId}/pembahasan', [UserTryoutController::class, 'pembahasan']);

    // Competitive Programming endpoints (Native IDE)
    Route::get('/user/cp/problems/{id}', [\App\Http\Controllers\User\CpSubmissionController::class, 'getProblem']);
    Route::get('/user/cp/problems/{id}/submissions', [\App\Http\Controllers\User\CpSubmissionController::class, 'submissions']);
    Route::post('/user/cp/problems/{id}/submit', [\App\Http\Controllers\User\CpSubmissionController::class, 'submitCode']);
    
    Route::get('/user/cp/packages', [\App\Http\Controllers\User\CpSubmissionController::class, 'packages']);
    Route::get('/user/cp/packages/{id}/problems', [\App\Http\Controllers\User\CpSubmissionController::class, 'packageProblems']);
});
