<?php

namespace Modules\WorkflowPacks\Services\Ai;

/**
 * What the AI and the similarity see of a ticket: subject, beginning of the first e-mail without the quoted messages
 * and the signature, sender's domain, a few hints. Anonymised by default: e-mail addresses, phone numbers, IBAN,
 * links and long numbers replaced by markers ([email], [phone]…). Never the attachments themselves.
 */
class TicketText
{
    const EXCERPT = 500;

    /** @return array subject, body (whole cleaned text), excerpt, from, from_domain, flags, attachments */
    public static function of($conversation, $anonymise = true)
    {
        $thread = $conversation->threads()->where('type', \App\Thread::TYPE_CUSTOMER)->orderBy('id')->first();
        $body = $thread ? self::clean((string) $thread->body) : '';
        $headers = $thread ? strtolower((string) $thread->headers) : '';
        $flags = [];
        if (strpos($headers, 'list-id:') !== false || strpos($headers, 'list-unsubscribe:') !== false || strpos($headers, 'precedence: bulk') !== false) {
            $flags[] = 'mailing_list';
        }
        if (preg_match('/auto-submitted:\s*auto-/', $headers) || strpos($headers, 'x-autoreply') !== false) {
            $flags[] = 'auto_reply';
        }
        $from = strtolower((string) $conversation->customer_email);
        $attachments = $thread ? $thread->attachments()->pluck('file_name')->map(function ($name) {
            return strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        })->filter()->unique()->values()->all() : [];
        $subject = (string) $conversation->subject;
        if ($anonymise) {
            $subject = self::anonymise($subject);
            $body = self::anonymise($body);
        }

        return [
            'subject'     => $subject,
            'body'        => $body,
            'excerpt'     => mb_substr($body, 0, self::EXCERPT),
            'from'        => $anonymise ? '' : $from,
            'from_domain' => strpos($from, '@') !== false ? substr($from, strpos($from, '@') + 1) : '',
            'flags'       => $flags,
            'attachments' => $attachments,
        ];
    }

    /** Text of an e-mail without HTML, quoted messages and signature. */
    public static function clean($html)
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8');
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            // a signature, or the message answered: the rest is not this e-mail
            if (preg_match('/^(-- ?$|_{5,}|(le|on|am|el|il|op|em) .{4,120}(a écrit|wrote|schrieb|escribió|ha scritto|schreef|escreveu) ?:)/iu', trim($line))) {
                break;
            }
            if (strpos(ltrim($line), '>') !== 0) {
                $lines[] = $line;
            }
        }

        return trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines))));
    }

    /** Personal data replaced by markers; the domain of a link is kept (it says what the e-mail is about). */
    public static function anonymise($text)
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[email]', (string) $text);
        $text = preg_replace_callback('#https?://([^/\s]+)\S*#i', function ($m) {
            return '[link:'.strtolower($m[1]).']';
        }, $text);
        $text = preg_replace('/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?\b/', '[iban]', $text);
        $text = preg_replace('/(?<!\w)\+?\d[\d .\-\/()]{7,}\d(?!\w)/', '[phone]', $text);

        return preg_replace('/\b\d{6,}\b/', '[number]', $text);
    }
}
