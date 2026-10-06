<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = ['sender_id', 'receiver_id', 'conversation_id', 'body', 'type', 'voice_path', 'voice_duration', 'voice_waveform', 'reply_to_message_id', 'file_path', 'file_name', 'file_size', 'file_mime_type'];
    protected $casts = [
        'voice_waveform' => 'array',
    ];
    public $timestamps = false;
    protected $appends = ['voice_url', 'reply_to', 'reactions', 'file_url'];

    public function getVoiceUrlAttribute()
    {
        return $this->voice_path ? asset('storage/' . $this->voice_path) : null;
    }

    public function getFileUrlAttribute()
    {
        return $this->file_path ? asset('storage/' . $this->file_path) : null;
    }

    public function getReplyToAttribute()
    {
        if (!$this->reply_to_message_id) return null;
        if (!$this->replyToMessage) return null;
        
        $body = $this->replyToMessage->body ?? '';
        $snippet = $this->replyToMessage->type === 'voice' ? 'Voice message' : (strlen($body) > 60 ? substr($body, 0, 60) . '...' : $body);

        return [
            'id' => $this->replyToMessage->id,
            'username' => $this->replyToMessage->sender->username ?? 'Unknown',
            'snippet' => $snippet,
        ];
    }
    
    public function getReactionsAttribute()
    {
        if (!$this->relationLoaded('reactionRecords')) {
            return new \stdClass(); // Return empty object if not loaded
        }
        
        $map = [];
        foreach ($this->reactionRecords as $reaction) {
            if (!isset($map[$reaction->emoji])) {
                $map[$reaction->emoji] = [];
            }
            $map[$reaction->emoji][] = $reaction->user_id;
        }
        
        return empty($map) ? new \stdClass() : $map;
    }

    public function reactionRecords()
    {
        return $this->hasMany(MessageReaction::class, 'message_id');
    }
    
    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }
    
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
