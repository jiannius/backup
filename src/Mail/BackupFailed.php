<?php

namespace Jiannius\Backup\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

class BackupFailed extends Mailable
{
    public function __construct(public Throwable $exception) {}

    /**
     * The message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Backup failed',
        );
    }

    /**
     * The message content.
     */
    public function content(): Content
    {
        // NOTE: 'message' is a reserved mail view variable — use 'error'.
        return new Content(
            markdown: 'backup::mail.failed',
            with: ['error' => $this->exception->getMessage()],
        );
    }
}
