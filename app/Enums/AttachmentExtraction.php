<?php

namespace App\Enums;

/**
 * Whether the words inside an uploaded file reached the model.
 *
 * Kept explicit because the failure that matters is the silent one: a scanned
 * 990 that yields no text would otherwise look identical to one the model read
 * in full, and the person would never learn their draft was written without it.
 */
enum AttachmentExtraction: string
{
    case Pending = 'pending';
    case Extracted = 'extracted';
    case Empty = 'empty';
    case Unsupported = 'unsupported';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Reading…',
            self::Extracted => 'Read',
            self::Empty => 'No text found',
            self::Unsupported => 'Cannot be read',
            self::Failed => 'Could not be read',
        };
    }

    public function reachedTheModel(): bool
    {
        return $this === self::Extracted;
    }

    /** What to tell the person when the words did not get through. */
    public function explanation(): ?string
    {
        return match ($this) {
            self::Empty => 'This file has no text in it. A scan or a photograph of a document is '.
                'a picture as far as the model is concerned — it was not used.',
            self::Unsupported => 'This kind of file cannot be read on this server, so it was not used.',
            self::Failed => 'Something went wrong reading this file, so it was not used.',
            default => null,
        };
    }
}
