<?php

declare(strict_types=1);

namespace Shopier\Http;

use Shopier\Exception\ShopierException;

/**
 * HTTP client that delegates to a curl-impersonate binary so requests carry a
 * real browser's TLS/JA3 and HTTP/2 fingerprint. This is what lets the storefront
 * (checkout) flow pass Cloudflare fingerprint-based blocks that plain PHP cURL
 * cannot, because PHP's bundled libcurl exposes a distinctive bot fingerprint.
 *
 * Requires curl-impersonate installed on the host. See:
 * https://github.com/lwthiker/curl-impersonate
 *
 * Usage:
 *   $client = new Shopier\Client($config, new CurlImpersonateHttpClient());
 *
 * Note: TLS impersonation defeats passive fingerprint/JA3 detection. It does NOT
 * execute JavaScript, so if Cloudflare still serves an interactive JS challenge
 * page ("Just a moment...") for the request, a headless browser is required.
 */
final class CurlImpersonateHttpClient implements HttpClientInterface
{
    private const MAX_REDIRECTS = 5;

    /**
     * @param string $binary     Path/name of the curl-impersonate binary
     *                           (e.g. "curl-impersonate-chrome" or a wrapper like "curl_chrome116").
     * @param string|null $target Impersonation target passed via --impersonate (e.g. "chrome116").
     *                            Pass null when using a wrapper script that already sets it.
     */
    public function __construct(
        private readonly string $binary = 'curl-impersonate-chrome',
        private readonly ?string $target = 'chrome116'
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?CookieJar $cookieJar = null,
        ?int $timeout = null
    ): Response {
        $headerFile = $this->tempFile('shopier-hdr-');
        $cookieFile = $this->tempFile('shopier-cookie-');

        try {
            $command = $this->buildCommand($method, $url, $headers, $body, $cookieJar, $timeout, $headerFile, $cookieFile);
            $result = $this->run($command, $body);

            $statusCode = (int) trim($result['stdout']);
            $rawHeaders = is_string(@file_get_contents($headerFile)) ? (string) file_get_contents($headerFile) : '';
            $parsedHeaders = $this->parseHeaders($rawHeaders);

            $response = new Response($statusCode, $parsedHeaders, $result['body']);

            if ($cookieJar !== null) {
                // Set-Cookie lines across every redirect hop are in the header dump.
                $cookieJar->addFromResponse($response);
                $this->mergeCookieFile($cookieFile, $cookieJar);
            }

            return $response;
        } finally {
            @unlink($headerFile);
            @unlink($cookieFile);
        }
    }

    /**
     * @param array<int|string, string> $headers
     * @return list<string>
     */
    private function buildCommand(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?CookieJar $cookieJar,
        ?int $timeout,
        string $headerFile,
        string $cookieFile
    ): array {
        $command = [$this->binary];

        if ($this->target !== null && $this->target !== '') {
            $command[] = '--impersonate';
            $command[] = $this->target;
        }

        $command[] = '-s';                       // silent
        $command[] = '--compressed';             // request + transparently decode gzip/br/zstd
                                                 // (Chrome impersonation sends Accept-Encoding: gzip, br, zstd;
                                                 // without this the body stays compressed and JSON parsing fails)
        $command[] = '-L';                       // follow redirects (cookie engine carries cookies across hops)
        $command[] = '--max-redirs';
        $command[] = (string) self::MAX_REDIRECTS;
        $command[] = '--max-time';
        $command[] = (string) ($timeout ?? 30);
        $command[] = '-X';
        $command[] = strtoupper($method);
        $command[] = '-D';                       // dump response headers (all hops)
        $command[] = $headerFile;
        $command[] = '-c';                       // write final cookie jar
        $command[] = $cookieFile;
        $command[] = '-o';                       // body to stdout via /dev/stdout? keep on stdout below
        $command[] = '-';                        // "-o -" => body to stdout
        $command[] = '-w';                       // append status code after body; separated by sentinel
        $command[] = "\n%{http_code}";

        if ($cookieJar !== null && !$cookieJar->isEmpty()) {
            $command[] = '-b';
            $command[] = $cookieJar->header();   // contains '=', treated as a cookie string, not a filename
        }

        foreach ($this->normalizeHeaders($headers) as $headerLine) {
            $command[] = '-H';
            $command[] = $headerLine;
        }

        if ($body !== null) {
            $command[] = '--data-binary';
            $command[] = '@-';                   // read request body from stdin
        }

        $command[] = $url;

        return $command;
    }

    /**
     * @param list<string> $command
     * @return array{body: string, stdout: string}
     */
    private function run(array $command, ?string $body): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Array form of proc_open avoids shell parsing, so URLs/headers/cookies
        // cannot inject shell commands.
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new ShopierException(sprintf(
                'Unable to start curl-impersonate binary "%s". Is curl-impersonate installed and on PATH? See https://github.com/lwthiker/curl-impersonate',
                $this->binary
            ));
        }

        if ($body !== null) {
            fwrite($pipes[0], $body);
        }

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new ShopierException(sprintf(
                'curl-impersonate request failed (exit code %d): %s',
                $exitCode,
                trim($stderr) !== '' ? trim($stderr) : 'no error output'
            ));
        }

        // "-w \n%{http_code}" appended the status code on the final line.
        $separator = strrpos($stdout, "\n");

        if ($separator === false) {
            return ['body' => '', 'stdout' => $stdout];
        }

        return [
            'body' => substr($stdout, 0, $separator),
            'stdout' => substr($stdout, $separator + 1),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (preg_split('/\r\n|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'HTTP/') || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }

        return $headers;
    }

    private function mergeCookieFile(string $cookieFile, CookieJar $cookieJar): void
    {
        $contents = @file_get_contents($cookieFile);

        if (!is_string($contents) || $contents === '') {
            return;
        }

        foreach (preg_split('/\r\n|\n/', $contents) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Netscape cookie file: domain \t flag \t path \t secure \t expiry \t name \t value
            $columns = explode("\t", $line);

            if (count($columns) < 7) {
                continue;
            }

            $name = trim($columns[5]);
            $value = $columns[6];

            if ($name !== '') {
                $cookieJar->addFromSetCookieHeader($name . '=' . $value);
            }
        }
    }

    /**
     * @param array<int|string, string> $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                // Let curl-impersonate own the User-Agent so we don't send a duplicate.
                if ($this->target !== null && stripos((string) $value, 'user-agent:') === 0) {
                    continue;
                }

                $normalized[] = (string) $value;
                continue;
            }

            if ($this->target !== null && strtolower($name) === 'user-agent') {
                continue;
            }

            $normalized[] = $name . ': ' . $value;
        }

        return $normalized;
    }

    private function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);

        if ($file === false) {
            throw new ShopierException('Unable to create a temporary file for curl-impersonate.');
        }

        return $file;
    }
}
