<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;
    // name, description
    protected $fillable = ['name', 'description'];


    public function userEmails()
    {
        return $this->belongsToMany(UserEmail::class, 'group_user_emails')
            ->select('user_emails.id', 'user_emails.email', 'user_emails.name', 'user_emails.created_at')
            ->orderBy('user_emails.created_at', 'desc');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
