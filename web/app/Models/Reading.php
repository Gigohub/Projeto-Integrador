<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Reading extends Model
{
    public $timestamps = false;
    protected $fillable = ['device_id', 'user_id', 'value_enc', 'ts'];
    protected $casts = ['ts' => 'datetime'];

    public function setPayloadAttribute(array $data): void
    {
        $this->attributes['value_enc'] = Crypt::encryptString(json_encode($data));
    }

    public function getPayloadAttribute(): array
    {
        try {
            return json_decode(Crypt::decryptString($this->value_enc), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}