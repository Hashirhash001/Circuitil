<?php

namespace App\Models;

use App\Models\Brand;
use App\Models\Influencer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CollaborationRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['collaboration_id', 'influencer_id', 'status'];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function influencer()
    {
        return $this->belongsTo(Influencer::class);
    }

    // Define the relationship with the Collaboration model
    public function collaboration()
    {
        return $this->belongsTo(Collaboration::class, 'collaboration_id');
    }
}
