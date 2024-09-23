<?php

namespace App\Models;

use App\Models\User;
use App\Models\PostLike;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['user_id', 'image', 'description'];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with Post Likes
    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

}
