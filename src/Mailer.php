<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free SMTP mailer.
 *
 * Sends plain-text mail over SMTP with optional STARTTLS + AUTH LOGIN, which is
 * what the Damascus Exchange host (smtp.us.exg7.exghost.com:587, TLS) expects.
 * No Composer/PHPMailer dependency so it runs on the plain XAMPP box.
 *
 * Configuration comes from the environment / .env (see config/config.php):
 *   SMTP_HOST       e.g. smtp.us.exg7.exghost.com
 *   SMTP_PORT       e.g. 587
 *   SMTP_SECURE     tls (STARTTLS, default) | ssl (implicit) | none
 *   SMTP_USER       mailbox to authenticate as
 *   SMTP_PASSWORD   mailbox password (store as a secret, never commit)
 *   SMTP_FROM       From address (defaults to SMTP_USER)
 *   SMTP_FROM_NAME  From display name (optional)
 *   SMTP_TIMEOUT    socket timeout seconds (default 15)
 *
 * NOTE: the Exchange host rejects connections from outside the company LAN, so
 * this must run on the XAMPP box. Use dry-run to compose without sending.
 */
final class Mailer
{
    private string $host;
    private int $port;
    private string $secure;      // tls | ssl | none
    private ?string $user;
    private ?string $pass;
    private string $from;
    private string $fromName;
    private int $timeout;

    /** @var array<int, string> transcript of the SMTP conversation (for debugging). */
    private array $log = [];

    public function __construct()
    {
        $this->host     = (string) env('SMTP_HOST', '');
        $this->port     = (int) env('SMTP_PORT', 587);
        $this->secure   = strtolower((string) (env('SMTP_SECURE', 'tls') ?: 'tls'));
        $this->user     = (string) env('SMTP_USER', '') ?: null;
        $this->pass     = (string) env('SMTP_PASSWORD', '') ?: null;
        $this->from     = (string) (env('SMTP_FROM', $this->user) ?: ($this->user ?? ''));
        $this->fromName = (string) env('SMTP_FROM_NAME', '');
        $this->timeout  = (int) env('SMTP_TIMEOUT', 15);
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->from !== '';
    }

    /** @return array<int, string> */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Compose an RFC-822 message (headers + body) without sending. Useful for
     * dry-run and tests.
     *
     * @param array<int, string> $to
     */
    public function compose(array $to, string $subject, string $body): string
    {
        $fromHeader = $this->fromName !== ''
            ? sprintf('%s <%s>', $this->encodeHeader($this->fromName), $this->from)
            : $this->from;

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromHeader,
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // Normalise to CRLF and dot-stuff lines beginning with '.'.
        $normBody = preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
        $normBody = preg_replace('/^\./m', '..', $normBody) ?? $normBody;

        return implode("\r\n", $headers) . "\r\n\r\n" . $normBody . "\r\n";
    }

    /**
     * Send a message. When $dryRun is true, no connection is made — the
     * composed message is returned and nothing is transmitted.
     *
     * @param array<int, string>|string $to
     * @return array{ok:bool, dry_run:bool, message:string, error:?string}
     */
    public function send(array|string $to, string $subject, string $body, bool $dryRun = false): array
    {
        $recipients = array_values(array_filter(array_map('trim', (array) $to)));
        $composed = $this->compose($recipients, $subject, $body);

        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'message' => $composed, 'error' => null];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'dry_run' => false, 'message' => $composed, 'error' => 'SMTP not configured (set SMTP_HOST / SMTP_FROM).'];
        }
        if ($recipients === []) {
            return ['ok' => false, 'dry_run' => false, 'message' => $composed, 'error' => 'No recipients.'];
        }

        try {
            $this->transmit($recipients, $composed);
            return ['ok' => true, 'dry_run' => false, 'message' => $composed, 'error' => null];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'dry_run' => false, 'message' => $composed, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<int, string> $recipients
     */
    private function transmit(array $recipients, string $data): void
    {
        $transport = $this->secure === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new RuntimeException("Connect failed: $errstr ($errno)");
        }
        stream_set_timeout($socket, $this->timeout);

        $this->expect($socket, 220);
        $host = gethostname() ?: 'localhost';

        $this->cmd($socket, 'EHLO ' . $host, 250);

        if ($this->secure === 'tls') {
            $this->cmd($socket, 'STARTTLS', 220);
            $ok = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );
            if ($ok !== true) {
                throw new RuntimeException('STARTTLS negotiation failed.');
            }
            $this->cmd($socket, 'EHLO ' . $host, 250);
        }

        if ($this->user !== null && $this->pass !== null) {
            $this->cmd($socket, 'AUTH LOGIN', 334);
            $this->cmd($socket, base64_encode($this->user), 334);
            $this->cmd($socket, base64_encode($this->pass), 235);
        }

        $this->cmd($socket, 'MAIL FROM:<' . $this->from . '>', 250);
        foreach ($recipients as $rcpt) {
            $this->cmd($socket, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
        }
        $this->cmd($socket, 'DATA', 354);

        fwrite($socket, $data . "\r\n.\r\n");
        $this->expect($socket, 250);

        $this->cmd($socket, 'QUIT', [221], true);
        fclose($socket);
    }

    /**
     * @param resource            $socket
     * @param int|array<int,int>  $expected
     */
    private function cmd($socket, string $command, int|array $expected, bool $ignoreErrors = false): void
    {
        // Never log the base64 credentials verbatim.
        $this->log[] = 'C: ' . (preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $command) ? '****' : $command);
        fwrite($socket, $command . "\r\n");
        try {
            $this->expect($socket, $expected);
        } catch (RuntimeException $e) {
            if (!$ignoreErrors) {
                throw $e;
            }
        }
    }

    /**
     * @param resource           $socket
     * @param int|array<int,int> $expected
     */
    private function expect($socket, int|array $expected): void
    {
        $codes = (array) $expected;
        $line = '';
        // Read the (possibly multi-line) reply; continuation lines have a '-'
        // after the code, the final line has a space.
        do {
            $chunk = fgets($socket, 515);
            if ($chunk === false) {
                $meta = stream_get_meta_data($socket);
                $why = ($meta['timed_out'] ?? false) ? 'timed out' : 'connection closed';
                throw new RuntimeException('SMTP read failed (' . $why . '). Last: ' . trim($line));
            }
            $line = $chunk;
            $this->log[] = 'S: ' . rtrim($line);
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Unexpected SMTP reply: ' . trim($line));
        }
    }

    /** RFC 2047 encode a header value if it contains non-ASCII. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
