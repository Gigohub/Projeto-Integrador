<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Command extends Model
{
    public $timestamps = false;
    protected $fillable = ['name', 'value_enc', 'user_id', 'acked', 'ts'];
    protected $casts = ['acked' => 'boolean', 'ts' => 'datetime'];

    public function setDecodedValueAttribute($val): void
    {
        $this->attributes['value_enc'] = Crypt::encryptString(json_encode($val));
    }

    public function getDecodedValueAttribute()
    {
        try {
            return json_decode(Crypt::decryptString($this->value_enc), true);
        } catch (\Throwable) {
            return null;
        }
    }
}