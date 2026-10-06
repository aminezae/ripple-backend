<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'name'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'conversation_user')->withPivot('created_at', 'last_read_message_id', 'is_pinned', 'is_muted');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
