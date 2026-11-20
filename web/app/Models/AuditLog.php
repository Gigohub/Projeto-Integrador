<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AuditLog extends Model
{
    protected $table = 'logs';
    public $timestamps = false;
    protected $fillable = ['user_id', 'ip', 'action_enc', 'ts'];
    protected $casts = ['ts' => 'datetime'];

    public static function record(string $action, ?int $userId = null): void
    {
        static::create([
            'user_id'    => $userId,
            'ip'         => request()->ip(),
            'action_enc' => Crypt::encryptString($action),
            'ts'         => now(),
        ]);
    }

    public function getActionAttribute(): string
    {
        try {
            return Crypt::decryptString($this->action_enc);
        } catch (\Throwable) {
            return '[Log ilegível]';
        }
    }
}