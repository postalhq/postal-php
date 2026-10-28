<?php

declare(strict_types=1);

namespace Postal\Laravel;

use Postal\Client;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Symfony Mailer (and Laravel) mail transport that sends through the Postal API (`POST /emails`).
 *
 * Attachments, extra headers and tags (`TagHeader`, `MetadataHeader`) go along. The ID of the
 * email in Postal is in `SentMessage::getMessageId()` and in the `X-Postal-Email-Id` header of
 * `SentMessage::getOriginalMessage()`.
 */
class PostalTransport extends AbstractTransport
{
    /**
     * Headers the API does not let you override or that Postal builds by itself.
     */
    private const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender', 'date', 'message-id', 'return-path',
        'mime-version', 'content-type', 'content-transfer-encoding', 'dkim-signature', 'x-postal-email-id',
    ];

    public function __construct(private readonly Client $client)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'postal';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $response = $this->client->emails->send($this->payload($email, $message));
        } catch (Throwable $exception) {
            throw new TransportException('Could not send through Postal: '.$exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $message->setMessageId($response['id']);
        $message->getOriginalMessage()->getHeaders()->addTextHeader('X-Postal-Email-Id', $response['id']);
    }

    /**
     * The body of `POST /emails` for the message.
     *
     * @return array<string, mixed>
     */
    public function payload(Email $email, SentMessage $message): array
    {
        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $to = self::addresses($email->getTo());
        $cc = self::addresses($email->getCc());
        $bcc = self::addresses($email->getBcc());
        $seen = array_map(
            static fn (Address $address): string => strtolower($address->getAddress()),
            [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()],
        );

        // Recipients only in the envelope (for example, a Bcc that Laravel took out of the headers) go as blind copies.
        foreach ($message->getEnvelope()->getRecipients() as $recipient) {
            if (! in_array(strtolower($recipient->getAddress()), $seen, true)) {
                $bcc[] = $recipient->getAddress();
            }
        }

        // The API requires "to": a message with only blind copies sends all the addresses in "to".
        if ($to === [] && $cc === []) {
            $to = $bcc;
            $bcc = [];
        }

        $payload = [
            'from' => $from->toString(),
            'to' => $to,
            'subject' => (string) $email->getSubject(),
        ];

        foreach (['cc' => $cc, 'bcc' => $bcc, 'reply_to' => self::addresses($email->getReplyTo())] as $field => $list) {
            if ($list !== []) {
                $payload[$field] = $list;
            }
        }

        if ($email->getHtmlBody() !== null) {
            $payload['html'] = self::bodyToString($email->getHtmlBody());
        }

        if ($email->getTextBody() !== null) {
            $payload['text'] = self::bodyToString($email->getTextBody());
        }

        $headers = $this->customHeaders($email);

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $tags = $this->tags($email);

        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function customHeaders(Email $email): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();

            if (in_array(strtolower($name), self::RESERVED_HEADERS, true) || $header instanceof TagHeader || $header instanceof MetadataHeader) {
                continue;
            }

            $value = trim(str_replace(["\r", "\n"], ' ', $header->getBodyAsString()));

            if ($value !== '' && strlen($value) <= 998) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * `TagHeader` becomes the `tag` tag; each `MetadataHeader`, a tag named after its key.
     *
     * @return list<array{name: string, value: string}>
     */
    private function tags(Email $email): array
    {
        $tags = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $tags[] = ['name' => 'tag', 'value' => self::tagText($header->getValue())];
            } elseif ($header instanceof MetadataHeader) {
                $tags[] = ['name' => self::tagText($header->getKey()), 'value' => self::tagText($header->getValue())];
            }
        }

        return array_slice($tags, 0, 50);
    }

    /**
     * @return list<array<string, string>>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            $headers = $part->getPreparedHeaders();
            $attachment = [
                'content' => base64_encode($part->getBody()),
                'content_type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
            ];
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');

            if ($filename !== null) {
                $attachment['filename'] = $filename;
            }

            if ($headers->getHeaderBody('Content-Disposition') === 'inline') {
                $attachment['content_id'] = $part->getContentId();
            }

            $attachments[] = $attachment;
        }

        return $attachments;
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->toString(), $addresses));
    }

    /**
     * @param  resource|string  $body
     */
    private static function bodyToString(mixed $body): string
    {
        return is_resource($body) ? (string) stream_get_contents($body) : (string) $body;
    }

    /**
     * A Postal tag accepts only ASCII letters, numbers, `_` and `-`, up to 256 characters.
     */
    private static function tagText(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? '';

        return substr($clean === '' ? '_' : $clean, 0, 256);
    }
}
