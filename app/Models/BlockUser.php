<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockUser extends Model
{
    use HasFactory;

    protected $table = 'blocked_users';

    protected $fillable = ['blocker_id', 'blocked_id'];

    // Relationship: Blocker (User who blocked)
    public function blocker()
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    // Relationship: Blocked User
    public function blockedUser()
    {
        return $this->belongsTo(User::class, 'blocked_id')->select('id', 'name', 'email', 'role');
    }

}
