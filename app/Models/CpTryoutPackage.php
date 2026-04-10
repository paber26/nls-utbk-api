<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CpTryoutPackage extends Model
{
    protected $fillable = [
        'nama_paket',
        'durasi_menit',
        'mulai',
        'selesai',
        'status',
        'created_by',
    ];

    protected $casts = [
        'mulai' => 'datetime',
        'selesai' => 'datetime',
    ];

    public function problems(): BelongsToMany
    {
        return $this->belongsToMany(
            CpProblem::class,
            'cp_tryout_package_problems',
            'cp_tryout_package_id',
            'cp_problem_id'
        )->withPivot('urutan')->withTimestamps()->orderBy('cp_tryout_package_problems.urutan');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

