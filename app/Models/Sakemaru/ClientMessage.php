<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMessage extends CustomModel
{
    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function addMessage(string $message): self
    {
        return self::create([
            'client_id' => auth()->user()->client_id,
            'message' => $message,
        ]);
    }

    public static function getMessages(): array
    {
        return self::where([
            'client_id' => auth()->user()->client_id,
        ])
            ->pluck('message')
            ->toArray();
    }
}
