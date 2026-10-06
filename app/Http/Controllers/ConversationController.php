<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $conversations = $user->conversations()
            ->with('users')
            ->withMax('messages', 'created_at')
            ->withCount(['messages as unread_count' => function ($query) use ($user) {
                $query->where('sender_id', '!=', $user->id)
                      ->whereRaw('(messages.id > conversation_user.last_read_message_id OR conversation_user.last_read_message_id IS NULL)');
            }])
            ->get();

        $conversations = $conversations->sortByDesc(function ($conv) {
            $isPinned = $conv->pivot->is_pinned ? 1 : 0;
            $timestamp = $conv->messages_max_created_at ? strtotime($conv->messages_max_created_at) : 0;
            return sprintf('%d_%015d', $isPinned, $timestamp);
        })->values();

        $formatted = $conversations->map(function ($conv) use ($user) {
            if ($conv->type === 'direct') {
                $otherUser = $conv->users->firstWhere('id', '!=', $user->id);
                $conv->display_name = $otherUser ? $otherUser->username : 'Unknown';
            } else {
                $conv->display_name = $conv->name;
            }
            return [
                'id' => $conv->id,
                'type' => $conv->type,
                'name' => $conv->display_name,
                'unread_count' => $conv->unread_count,
                'is_pinned' => (bool) $conv->pivot->is_pinned,
                'is_muted' => (bool) $conv->pivot->is_muted,
                'users' => $conv->users->map(fn($u) => ['id' => $u->id, 'username' => $u->username])
            ];
        });

        return response()->json($formatted);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:direct,group',
            'participant_ids' => 'required|array',
            'participant_ids.*' => 'exists:users,id',
            'name' => 'required_if:type,group|string|max:255'
        ]);

        $user = $request->user();
        $participantIds = collect($request->participant_ids)->push($user->id)->unique()->values();

        if ($request->type === 'direct') {
            if ($participantIds->count() !== 2) {
                return response()->json(['message' => 'Direct conversation must have exactly 2 unique participants.'], 422);
            }

            // Check if direct conversation already exists between these two users
            $existingConv = Conversation::where('type', 'direct')
                ->whereHas('users', function ($q) use ($participantIds) {
                    $q->whereIn('users.id', $participantIds);
                }, '=', 2)
                ->first();

            if ($existingConv) {
                return response()->json($existingConv, 200);
            }

            $conversation = Conversation::create(['type' => 'direct']);
            $conversation->users()->attach($participantIds);
            
            return response()->json($conversation, 201);
        } else {
            // Group chat
            if ($participantIds->count() < 3) {
                return response()->json(['message' => 'Group conversation must have at least 3 participants.'], 422);
            }

            $conversation = Conversation::create([
                'type' => 'group',
                'name' => $request->name
            ]);
            
            $conversation->users()->attach($participantIds);
            
            return response()->json($conversation, 201);
        }
    }

    public function messages(Request $request, $id)
    {
        $user = $request->user();
        $conversation = Conversation::with('users')->findOrFail($id);

        if (!$conversation->users->contains('id', $user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = $conversation->messages()
            ->with(['sender:id,username', 'replyToMessage.sender:id,username', 'reactionRecords'])
            ->orderBy('created_at', 'asc')
            ->get();
        $otherUsers = $conversation->users->where('id', '!=', $user->id);

        foreach ($messages as $msg) {
            if ($msg->sender_id === $user->id) {
                $allRead = true;
                foreach ($otherUsers as $ou) {
                    if (!$ou->pivot->last_read_message_id || $ou->pivot->last_read_message_id < $msg->id) {
                        $allRead = false;
                        break;
                    }
                }
                $msg->status = ($allRead && $otherUsers->count() > 0) ? 'read' : 'delivered';
            }
        }

        return response()->json($messages);
    }

    public function sendMessage(Request $request, $id)
    {
        $request->validate([
            'body' => 'required|string',
            'reply_to_message_id' => 'nullable|integer|exists:messages,id,conversation_id,' . $id
        ]);

        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if (!$conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'body' => $request->body,
            'reply_to_message_id' => $request->reply_to_message_id
        ]);
        
        $message->refresh(); // get created_at
        $message->load(['sender:id,username', 'replyToMessage.sender:id,username', 'reactionRecords']);

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    public function sendVoiceMessage(Request $request, $id)
    {
        $request->validate([
            'audio' => 'required|file|mimes:webm,mp4,ogg,mpga,wav,aac|max:5120',
            'duration' => 'required|integer|min:1',
            'waveform' => 'nullable|string',
            'reply_to_message_id' => 'nullable|integer|exists:messages,id,conversation_id,' . $id
        ]);

        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if (!$conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $path = $request->file('audio')->store('voice-messages', 'public');
        
        $waveformArray = $request->has('waveform') ? json_decode($request->waveform, true) : null;

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'type' => 'voice',
            'voice_path' => $path,
            'voice_duration' => $request->duration,
            'voice_waveform' => $waveformArray,
            'body' => null,
            'reply_to_message_id' => $request->reply_to_message_id
        ]);
        
        $message->refresh(); // get created_at
        $message->load(['sender:id,username', 'replyToMessage.sender:id,username', 'reactionRecords']);

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    public function sendFileMessage(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpeg,png,gif,webp,pdf,txt,doc,docx,zip',
            'reply_to_message_id' => 'nullable|integer|exists:messages,id,conversation_id,' . $id
        ]);

        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if (!$conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $file = $request->file('file');
        $path = $file->store('attachments', 'public');
        $mime = $file->getMimeType();
        $isImage = str_starts_with($mime, 'image/');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'type' => $isImage ? 'image' : 'file',
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_mime_type' => $mime,
            'body' => null,
            'reply_to_message_id' => $request->reply_to_message_id
        ]);
        
        $message->refresh(); // get created_at
        $message->load(['sender:id,username', 'replyToMessage.sender:id,username', 'reactionRecords']);

        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    public function downloadFile(Request $request, $id)
    {
        $user = $request->user();
        $message = Message::findOrFail($id);

        if (!$message->conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$message->file_path) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->download($message->file_path, $message->file_name);
    }

    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if (!$conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $latestMessageId = $conversation->messages()->max('id');
        if ($latestMessageId) {
            $conversation->users()->updateExistingPivot($user->id, [
                'last_read_message_id' => $latestMessageId
            ]);
            broadcast(new \App\Events\ConversationRead($conversation->id, $user->id, $latestMessageId))->toOthers();
        }

        return response()->json(['status' => 'success']);
    }

    public function toggleReaction(Request $request, $id)
    {
        $request->validate(['emoji' => 'required|string|max:50']);
        $user = $request->user();
        $message = Message::findOrFail($id);
        
        if (!$message->conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $existing = \App\Models\MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->emoji === $request->emoji) {
                $existing->delete();
            } else {
                $existing->update(['emoji' => $request->emoji]);
            }
        } else {
            \App\Models\MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $request->emoji
            ]);
        }

        $message->load('reactionRecords');
        broadcast(new \App\Events\ReactionUpdated($message->id, $message->conversation_id, $message->reactions))->toOthers();

        return response()->json(['reactions' => $message->reactions]);
    }

    public function deleteReaction(Request $request, $id)
    {
        $user = $request->user();
        $message = Message::findOrFail($id);
        
        if (!$message->conversation->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        \App\Models\MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->delete();

        $message->load('reactionRecords');
        broadcast(new \App\Events\ReactionUpdated($message->id, $message->conversation_id, $message->reactions))->toOthers();

        return response()->json(['reactions' => $message->reactions]);
    }

    public function togglePin(Request $request, $id)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        $userConv = $conversation->users()->where('users.id', $user->id)->first();
        if (!$userConv) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $newPinned = !$userConv->pivot->is_pinned;
        $conversation->users()->updateExistingPivot($user->id, [
            'is_pinned' => $newPinned
        ]);

        return response()->json(['status' => 'success', 'is_pinned' => $newPinned]);
    }

    public function toggleMute(Request $request, $id)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        $userConv = $conversation->users()->where('users.id', $user->id)->first();
        if (!$userConv) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $newMuted = !$userConv->pivot->is_muted;
        $conversation->users()->updateExistingPivot($user->id, [
            'is_muted' => $newMuted
        ]);

        return response()->json(['status' => 'success', 'is_muted' => $newMuted]);
    }
}
