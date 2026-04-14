<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    protected $table = 'attempt';
    public $timestamps = false;
    
    protected $fillable = [
        'tryout_id',
        'user_id',
        'mulai',
        'selesai',
        'status',
        'nilai',
        'nilai_komponen',
    ];

    protected $casts = [
        'mulai' => 'datetime',
        'selesai' => 'datetime',
        'nilai_komponen' => 'array',
    ];

    public function tryout()
    {
        return $this->belongsTo(Tryout::class, 'tryout_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    public function jawabanPeserta()
    {
        return $this->hasMany(
            JawabanPeserta::class,
            'attempt_id'
        );
    }
}