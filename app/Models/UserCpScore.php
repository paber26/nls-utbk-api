<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCpScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'cf_handle',
        'total_points',
        'solved_count'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
