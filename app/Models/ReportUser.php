<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportUser extends Model
{
    use HasFactory;

    protected $table = 'reported_users';

    protected $fillable = ['reporter_id', 'reported_id', 'post_id', 'reason'];
}
