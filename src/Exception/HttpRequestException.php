<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Exception;

use RuntimeException;
use Throwable;

final class HttpRequestException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        private readonly ?string $diagnosticUrl = null,
        private readonly ?string $diagnosticBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * getMessage() deliberately excludes the response body and the
     * request URL's userinfo/query string — a query string routinely
     * carries a signed URL's signature, an API key, or a reset token,
     * and an upstream error body can carry PII, credentials, or payment
     * details. Framework/job exception paths commonly log an exception's
     * message (and the exception object itself) by default, which would
     * otherwise turn routine error logging into data exfiltration. The
     * full, unredacted request URL and a truncated response-body excerpt
     * are still reachable, but only through the explicit diagnosticUrl()/
     * diagnosticBody()/diagnosticMessage() accessors below — never assumed
     * safe to log without a deliberate choice to include them, and never
     * reachable through a generic serializer either: both fields are
     * private, so json_encode($exception) (or any other implicit
     * reflection over the object's public state) never exposes them.
     */
    public static function errorStatus(string $method, string $url, int $status, string $body): self
    {
        // A byte cap, not a character cap — mb_substr() would need
        // ext-mbstring, which nothing this package requires guarantees.
        $excerpt = strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;

        return new self(
            "{$method} " . self::redact($url) . " returned HTTP {$status}.",
            $status,
            diagnosticUrl: $url,
            diagnosticBody: $excerpt,
        );
    }

    /**
     * No response at all — DNS failure, a refused connection, a timeout.
     * `$status` is 0, since there is no HTTP answer to report.
     *
     * getMessage() never includes $previous->getMessage() verbatim — a
     * transport exception commonly names the URL it failed to reach,
     * userinfo and all (confirmed directly: a real connection failure to
     * a userinfo-bearing URL produced a message with the raw credentials
     * embedded in it), so copying that untrusted text into this class's
     * own safe-by-default message would defeat the redaction above
     * entirely. Only the previous exception's own class name — never its
     * message — is included; the full original is still reachable via
     * getPrevious(), a deliberate call rather than an implicit one.
     */
    public static function transportFailure(string $method, string $url, Throwable $previous): self
    {
        return new self(
            "{$method} " . self::redact($url) . ' failed before any response arrived: '
                . self::safeCategory($previous) . '. See getPrevious() for the original exception.',
            0,
            diagnosticUrl: $url,
            previous: $previous,
        );
    }

    /** See transportFailure() for why $previous->getMessage() is never included here. */
    public static function notJson(string $method, string $url, int $status, Throwable $previous): self
    {
        return new self(
            "{$method} " . self::redact($url) . " returned HTTP {$status} with a body that is not valid JSON ("
                . self::safeCategory($previous) . '). See getPrevious() for the original exception.',
            $status,
            diagnosticUrl: $url,
            previous: $previous,
        );
    }

    /**
     * The full, unredacted request URL captured at construction — the
     * complete userinfo and query string getMessage() deliberately
     * strips. Call this only where logging that detail is a deliberate,
     * considered choice, not a default.
     */
    public function diagnosticUrl(): ?string
    {
        return $this->diagnosticUrl;
    }

    /**
     * The response-body excerpt captured at construction (null when
     * nothing was captured — a transport failure has no response body at
     * all). Call this only where logging that detail is a deliberate,
     * considered choice, not a default.
     */
    public function diagnosticBody(): ?string
    {
        return $this->diagnosticBody;
    }

    /**
     * The full, unredacted detail getMessage() deliberately omits — the
     * complete request URL (userinfo and query string included) and the
     * response-body excerpt, when either was captured. Call this only
     * where logging that detail is a deliberate, considered choice, not
     * a default.
     */
    public function diagnosticMessage(): string
    {
        $message = $this->getMessage();

        if ($this->diagnosticUrl !== null) {
            $message .= " URL: {$this->diagnosticUrl}";
        }

        if ($this->diagnosticBody !== null && $this->diagnosticBody !== '') {
            $message .= " Response: {$this->diagnosticBody}";
        }

        return $message;
    }

    /**
     * The previous exception's own short class name (e.g.
     * "TransportException"), never its message — a safe-by-default
     * category to include in getMessage() when a lower-level exception's
     * own text can't be trusted not to carry a secret. The full original
     * throwable, message included, is always reachable via getPrevious().
     */
    private static function safeCategory(Throwable $previous): string
    {
        $class = $previous::class;
        $lastBackslash = strrpos($class, '\\');

        return $lastBackslash === false ? $class : substr($class, $lastBackslash + 1);
    }

    /**
     * Scheme, host, port, and path only — strips userinfo ("user:pass@")
     * and the query string, the two parts of a URL most likely to carry
     * a secret. Never throws: an unparseable URL falls back to a plain,
     * non-empty placeholder rather than surfacing a second exception
     * while building the first one's message.
     */
    private static function redact(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '(unparseable URL)';
        }

        $authority = ($parts['host'] ?? '') . (isset($parts['port']) ? ":{$parts['port']}" : '');
        $scheme = isset($parts['scheme']) ? "{$parts['scheme']}://" : '';

        return $scheme . $authority . ($parts['path'] ?? '');
    }
}
