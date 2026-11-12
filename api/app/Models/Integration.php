<?php

namespace App\Models;

use App\Casts\PostgresTextArray;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** OAuth connection to an external provider; tokens are encrypted by the application. */
#[Hidden(['access_token_enc', 'refresh_token_enc'])]
class Integration extends Model
{
    use BelongsToOwner, UsesTimestampTz;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'access_token_enc' => 'encrypted',
            'refresh_token_enc' => 'encrypted',
            'scopes' => PostgresTextArray::class,
            'last_synced_at' => 'datetime',
        ];
    }
}
