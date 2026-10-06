<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        if ($this->message->conversation_id) {
            return [
                new PrivateChannel('conversation.' . $this->message->conversation_id),
            ];
        }

        $userId1 = min($this->message->sender_id, $this->message->receiver_id);
        $userId2 = max($this->message->sender_id, $this->message->receiver_id);

        return [
            new PrivateChannel('chat.' . $userId1 . '.' . $userId2),
        ];
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message->toArray()];
    }
}
