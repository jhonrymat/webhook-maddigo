<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserEmail extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email'];

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_user_emails');
    }
}
