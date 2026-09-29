<?php

namespace App\Mail;

use App\Models\NotificationOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ask/Answer-Outbox-Mail (BL-P9-02c / PO-BLP902C-1).
 * Inhalt ausschließlich NOT-001-Felder – keine Rückfrage-/Antworttexte.
 */
class SalesInquiryOutboxMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly NotificationOutbox $outbox,
    ) {}

    public function envelope(): Envelope
    {
        $payload = $this->outbox->payload_json;
        $eventLabel = is_string($payload['event_label'] ?? null)
            ? $payload['event_label']
            : 'Benachrichtigung';
        $orderNumber = is_string($payload['order_number'] ?? null)
            ? $payload['order_number']
            : '';

        $subject = trim($eventLabel.($orderNumber !== '' ? ' – '.$orderNumber : ''));

        return new Envelope(
            subject: $subject !== '' ? $subject : 'Dispo-Benachrichtigung',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.sales-inquiry-outbox',
            with: [
                'recipientName' => $this->outbox->recipient_name,
                'payload' => $this->outbox->payload_json,
            ],
        );
    }
}
