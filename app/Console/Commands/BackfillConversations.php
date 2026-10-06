<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillConversations extends Command
{
    protected $signature = 'app:backfill-conversations';
    protected $description = 'Backfill conversations for existing direct messages';

    public function handle()
    {
        $this->info('Starting backfill...');
        
        // Get all unique pairs of sender_id and receiver_id
        $messages = DB::table('messages')->select('sender_id', 'receiver_id')->distinct()->get();
        
        $pairs = [];
        foreach ($messages as $msg) {
            if ($msg->sender_id && $msg->receiver_id) {
                $min = min($msg->sender_id, $msg->receiver_id);
                $max = max($msg->sender_id, $msg->receiver_id);
                $key = "{$min}_{$max}";
                if (!isset($pairs[$key])) {
                    $pairs[$key] = ['user1' => $min, 'user2' => $max];
                }
            }
        }

        foreach ($pairs as $key => $pair) {
            $user1 = $pair['user1'];
            $user2 = $pair['user2'];
            
            // Create a conversation
            $conversationId = DB::table('conversations')->insertGetId([
                'type' => 'direct',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Link users
            DB::table('conversation_user')->insert([
                ['conversation_id' => $conversationId, 'user_id' => $user1, 'created_at' => now()],
                ['conversation_id' => $conversationId, 'user_id' => $user2, 'created_at' => now()],
            ]);
            
            // Update messages
            DB::table('messages')
                ->where(function ($query) use ($user1, $user2) {
                    $query->where('sender_id', $user1)->where('receiver_id', $user2);
                })
                ->orWhere(function ($query) use ($user1, $user2) {
                    $query->where('sender_id', $user2)->where('receiver_id', $user1);
                })
                ->update(['conversation_id' => $conversationId]);
                
            $this->info("Created conversation {$conversationId} for users {$user1} and {$user2}");
        }
        
        $this->info('Backfill complete!');
        
        $nullCount = DB::table('messages')->whereNull('conversation_id')->count();
        $this->info("Messages with NULL conversation_id: {$nullCount}");
    }
}
