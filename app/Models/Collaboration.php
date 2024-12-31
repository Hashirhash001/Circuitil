<?php

namespace App\Models;

use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Collaboration extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['brand_id', 'name', 'image', 'description', 'category', 'end_date', 'amount' ,'status'];

    public function influencer()
    {
        return $this->belongsTo(Influencer::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function collaborationRequests()
    {
        return $this->hasMany(CollaborationRequest::class, 'collaboration_id');
    }
}
