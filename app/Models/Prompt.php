<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'active_version_id');
    }

    /**
     * Stores the content as a new version and makes it active. Saving
     * unchanged content does nothing; content equal to an older version
     * reactivates that version.
     */
    public function publish(string $content, ?string $note = null, ?int $userId = null): PromptVersion
    {
        $content = trim(str_replace("\r\n", "\n", $content));
        $hash = substr(sha1($content), 0, 12);

        $version = $this->versions()->where('version', $hash)->first()
            ?? $this->versions()->create([
                'content' => $content,
                'version' => $hash,
                'note' => $note,
                'user_id' => $userId,
            ]);

        $this->update(['active_version_id' => $version->id]);

        return $version;
    }
}
