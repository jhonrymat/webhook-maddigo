<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsletterStatistic extends Model
{
    use HasFactory;
    protected $fillable = [
        'newsletter_id',
        'email',
        'message_id',
        'status',
        'source_ip',
        'browser',
        'operating_system',
        'timestamp',
    ];
}
