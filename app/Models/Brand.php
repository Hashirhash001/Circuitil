<?php

namespace App\Models;

use App\Models\User;
use App\Models\Collaboration;
use App\Models\CollaborationRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'category', 'social_media_links', 'about', 'profile_photo'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function collaborations()
    {
        return $this->hasMany(Collaboration::class);
    }

    public function collaborationRequests()
    {
        return $this->hasMany(CollaborationRequest::class);
    }
}
