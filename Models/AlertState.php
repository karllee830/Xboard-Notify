<?php

declare(strict_types=1);

namespace Plugin\XboardNotify\Models;

use Illuminate\Database\Eloquent\Model;

final class AlertState extends Model
{
    protected $table = 'xboard_notify_alert_states';

    protected $guarded = ['id'];

    protected $casts = [
        'machine_id' => 'integer',
        'first_detected_at' => 'integer',
        'last_notified_at' => 'integer',
    ];
}
