<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsCampaign extends Model
{
    protected $fillable = [
        'title', 'message', 'total_recipients',
        'sent_count', 'delivered_count', 'failed_count',
        'status', 'shoot_id', 'created_by',
    ];

    public function logs()
    {
        return $this->hasMany(SmsLog::class, 'campaign_id');
    }
}
