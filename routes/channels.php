<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{userId1}.{userId2}', function ($user, $userId1, $userId2) {
    // Only allow the two participants to subscribe
    return (int) $user->id === (int) $userId1 || (int) $user->id === (int) $userId2;
});

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Only allow participants to subscribe
    return \Illuminate\Support\Facades\DB::table('conversation_user')
        ->where('conversation_id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});
