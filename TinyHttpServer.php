<?php
namespace Vilx2;

/**
 * A trivial, minimalistic HTTP server that can be used alongside other code for diagnostic purposes.
 */
class TinyHttpServer
{
    /** @var string  */
    private string $ip;
    /** @var int  */
    private int $port;
    /** @var resource */
    private $listenSocket = null;
    /** @var TinyHttpServerSocket[]  */
    private array $sockets = [];
    /** @var callable */
    private $handler;


    /**
     * @param callable $handler Request handler. A callback with two parameters: function(TinyHttpServerRequest $request, TinyHttpServerResponse $response).
     * @param ?string $ip IP address to which to listen to. Use "0.0.0.0" to listen on all addresses.
     * @param ?int $port Port number to listen to.
     */
    public function __construct(callable $handler, ?string $ip = null, ?int $port = null)
    {
        $this->ip = $ip ?? $_ENV['TINYHTTP_IP'] ?? '0.0.0.0';
        $this->port = $port ?? $_ENV['TINYHTTP_PORT'] ?? 8888;
        $this->handler = $handler;
    }

    /**
     * Activate and start listening to incoming http connections.
     */
    public function start(): void
    {
        if ($this->listenSocket) {
            return;
        }

        $this->listenSocket = stream_socket_server("tcp://{$this->ip}:{$this->port}", $errno, $errstr) ?: null;
        if (!$this->listenSocket) {
            throw new \Exception($errstr, $errno);
        }
        stream_set_blocking($this->listenSocket, false);
    }

    /**
     * Call this repeatedly to process new incoming and outgoing data.
     * @param float $min_time Minimum time that this function will execute, in seconds (fractions supported).
     *      The function will return after at least this much time has elapsed AND it has run out of things to do.
     *      Negative values are treated as 0.
     * @param float|null $max_time Maximum time that this function will execute, in seconds (fractions supported).
     *      The function will return as soon as this much time has elapsed, even if it still has things it could do.
     *      A small overshoot is still possible, and also if the callback function takes forever, then all bets are off.
     *      If 0, the function will return after 1 round of processing, however long that takes.
     *      If null, the function will return after the minimum time has elapsed and it has run out of things to do.
     *      Negative values are treated as 0. If $max_time < $min_time, the result is unpredictable.
     */
    public function process(float $min_time = 0, ?float $max_time = null): void
    {
        if ( !$this->listenSocket ) {
            return;
        }
        $startTs = hrtime(true);
        $minTs = max($min_time, 0) * 1_000_000_000 + $startTs; // Don't quit before this.
        $maxTs = $max_time === null ? null : ( max($max_time, 0) * 1_000_000_000 + $startTs); // Quit after this, even if there is more to do.

        while ( true ) {
            $read_sockets = ['listen' => $this->listenSocket];
            $write_sockets = [];
            $except_sockets = [];

            foreach ($this->sockets as $idx => $socket) {
                if ( $socket->expectData ) {
                    $read_sockets[$idx] = $socket->socket;
                }
                if ( $socket->writeBuffer ) {
                    $write_sockets[$idx] = $socket->socket;
                }
            }

            $curTs = hrtime(true);
            $waitTime = max($minTs-$curTs, 0);

            $result = stream_select($read_sockets, $write_sockets, $except_sockets, floor($waitTime / 1_000_000_000), floor(($waitTime % 1_000_000_000) / 1000));
            if ($result === false) {
                throw new \Exception("Error selecting active streams!");
            }
            if ($result == 0) {
                return;
            }

            foreach ($read_sockets as $idx => $socket) {
                if ($idx === 'listen') {
                    $new_socket = stream_socket_accept($socket, 0, $peer_name);
                    if ( $new_socket === false ) {
                        throw new \Exception("Error accepting incoming socket!");
                    }
                    stream_set_blocking($new_socket, false);
                    stream_set_read_buffer($new_socket, 0);
                    stream_set_write_buffer($new_socket, 0);
                    $this->sockets[] = new TinyHttpServerSocket($new_socket, $peer_name, $this->handler);
                } else {
                    $data = fread($socket, 1024);
                    if ( $data === false ) {
                        fclose($socket);
                        unset($this->sockets[$idx]);
                    } else {
                        $this->sockets[$idx]->readBuffer .= $data;
                        $this->sockets[$idx]->notifyDataRead();
                    }
                }
            }

            foreach ($write_sockets as $idx => $socket) {
                $written = @fwrite($socket, $this->sockets[$idx]->writeBuffer);
                if ( $written === false ) {
                    fclose($socket);
                    unset($this->sockets[$idx]);
                } else if ($written > 0) {
                    if ($written == strlen($this->sockets[$idx]->writeBuffer)) {
                        $this->sockets[$idx]->writeBuffer = '';
                        $this->sockets[$idx]->notifyDataWritten();
                    } else {
                        $this->sockets[$idx]->writeBuffer = substr($this->sockets[$idx]->writeBuffer, $written);
                    }
                }
            }

            foreach ( $this->sockets as $idx => $socket ) {
                if ( $socket->finished ) {
                    fclose($socket->socket);
                    unset($this->sockets[$idx]);
                }
            }

            if ( $maxTs !== null && hrtime(true) >= $maxTs ) {
                return;
            }
        }
    }

    public function stop(): void {
        if ( !$this->listenSocket ) {
            return;
        }
        foreach ( $this->sockets as $socket ) {
            fclose($socket->socket);
        }
        $this->sockets = [];

        $this->listenSocket = null;
    }
}
class TinyHttpServerSocket
{
    private const INTERNAL_STATE_READ_HEADERS = 1;
    private const INTERNAL_STATE_READ_BODY_WITH_LENGTH = 2;
    private const INTERNAL_STATE_READ_BODY_WITH_CHUNKS = 3;
    private const INTERNAL_STATE_HANDLE = 4;
    private const INTERNAL_STATE_SEND_RESPONSE = 5;

    public bool $expectData = true;
    public bool $finished = false;
    public string $readBuffer = '';
    public string $writeBuffer = '';

    private readonly TinyHttpServerRequest $request;
    private readonly TinyHttpServerResponse $response;
    private $handler;

    private ?int $bodyLength = null;
    private int $internalState = self::INTERNAL_STATE_READ_HEADERS;

    public function __construct(public $socket, string $peer_name, callable $handler)
    {
        $this->request = new TinyHttpServerRequest();
        $this->response = new TinyHttpServerResponse();
        $this->request->peer = $peer_name;
        $this->handler = $handler;
    }

    public function notifyDataRead()
    {
        do {
            $startingState = $this->internalState;

            switch ($this->internalState) {
                case self::INTERNAL_STATE_READ_HEADERS:
                    $this->parseHeaders();
                    break;
                case self::INTERNAL_STATE_READ_BODY_WITH_LENGTH:
                    $this->parseBodyLength();
                    break;
                case self::INTERNAL_STATE_READ_BODY_WITH_CHUNKS:
                    $this->parseBodyChunk();
                    break;
                case self::INTERNAL_STATE_HANDLE:
                    $this->handle();
                    break;
            }
        } while ($startingState != $this->internalState);

        return false;
    }

    public function notifyDataWritten()
    {
        if ($this->internalState == self::INTERNAL_STATE_SEND_RESPONSE) {
            $this->finished = true;
        }
    }

    private function parseHeaders()
    {
        $headerEnd = strpos($this->readBuffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return;
        }
        $headers = substr($this->readBuffer, 0, $headerEnd);
        $this->readBuffer = substr($this->readBuffer, $headerEnd + 4);
        foreach (explode("\r\n", $headers) as $headerLine) {
            $split = explode(':', $headerLine, 2);
            if (count($split) == 2) {
                $this->request->headers[strtolower($split[0])][] = ltrim($split[1]);
            } else {
                $this->request->headers[''][] = $headerLine;
            }
        }

        if (isset($this->request->headers['expect'])) {
            if ($this->request->headers['expect'][0] == '100-continue') {
                $this->writeBuffer = "HTTP/1.1 100 Continue\r\n\r\n";
            } else {
                $this->writeBuffer = "HTTP/1.1 400 Bad Request\r\n\r\n";
                $this->internalState = self::INTERNAL_STATE_SEND_RESPONSE;
                return;
            }
        }

        if (isset($this->request->headers['content-length'])) {
            $this->bodyLength = intval($this->request->headers['content-length'][0]);
            if ($this->bodyLength > 0) {
                $this->internalState = self::INTERNAL_STATE_READ_BODY_WITH_LENGTH;
            } else {
                $this->internalState = self::INTERNAL_STATE_HANDLE;
            }
        } else if (isset($this->request->headers['transfer-encoding']) && $this->request->headers['transfer-encoding'][0] == 'chunked') {
            $this->internalState = self::INTERNAL_STATE_READ_BODY_WITH_CHUNKS;
        } else {
            $this->internalState = self::INTERNAL_STATE_HANDLE;
        }
    }

    private function parseBodyLength()
    {
        $bufLen = strlen($this->readBuffer);
        if ($bufLen < $this->bodyLength) {
            return;
        }
        if ($bufLen > $this->bodyLength) {
            $this->request->body = substr($this->readBuffer, 0, $this->bodyLength);
            $this->readBuffer = substr($this->readBuffer, $this->bodyLength);
        } else {
            $this->request->body = $this->readBuffer;
            $this->readBuffer = '';
        }
        $this->internalState = self::INTERNAL_STATE_HANDLE;
    }

    private function parseBodyChunk()
    {
        while (true) {
            $p = strpos($this->readBuffer, "\r\n");
            if ($p === false) {
                return;
            }
            $chunkLenStr = substr($this->readBuffer, 0, $p);
            $extStart = strpos($chunkLenStr, ';');
            if ($extStart) {
                $chunkLenStr = substr($chunkLenStr, $extStart); // Ignore all extensions
            }
            $chunkLength = hexdec($chunkLenStr);

            if (strlen($this->readBuffer) >= $p + $chunkLength + 4) {
                if ( $chunkLength > 0 ) {
                    $this->request->body .= substr($this->readBuffer, $p + 2, $chunkLength);
                }
                $this->readBuffer = substr($this->readBuffer, $p + $chunkLength + 4);
            } else {
                return;
            }

            if ($chunkLength == 0) {
                $this->internalState = self::INTERNAL_STATE_HANDLE;
                return;
            }
        }
    }

    private function handle()
    {
        if (!isset($this->request->headers[''])) {
            $this->writeBuffer = "HTTP/1.1 400 Bad Request";
            $this->internalState = self::INTERNAL_STATE_SEND_RESPONSE;
            return;
        }

        $split = explode(' ', $this->request->headers[''][0]);
        if (count($split) != 3) {
            $this->writeBuffer = "HTTP/1.1 400 Bad Request";
            $this->internalState = self::INTERNAL_STATE_SEND_RESPONSE;
            return;
        }

        $this->request->method = strtoupper($split[0]);
        $this->request->path = $split[1];

        try {
            ($this->handler)($this->request, $this->response);
        } catch (\Throwable $ex) {
            $this->response->setHttpStatus(500);
            $this->response->headers['Content-Type'] = ['text/plain'];
            $this->response->body = $ex->__toString();
        }

        $this->writeBuffer = 'HTTP/1.1 ' . $this->response->status . "\r\n";
        foreach ($this->response->headers as $key => $values) {
            foreach ($values as $value) {
                $this->writeBuffer .= "$key: $value\r\n";
            }
        }
        $this->writeBuffer .= "\r\n";
        $this->writeBuffer .= $this->response->body;

        $this->internalState = self::INTERNAL_STATE_SEND_RESPONSE;
    }
}


class TinyHttpServerRequest
{
    public string $method = '';
    public string $path = '';
    /** @var string[][] */
    public array $headers = [];
    public ?string $body = null;
    public string $peer;
}

class TinyHttpServerResponse
{
    public string $status = '200 OK';
    /** @var string[][]  */
    public array $headers = [];
    public ?string $body = null;

    public function setHttpStatus(int $statusCode) {
        $knownCodes = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            103 => 'Early Hints',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            208 => 'Already Reported',
            226 => 'IM Used',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => 'Switch Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Payload Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            418 => "I'm a teapot",
            421 => 'Misdirected Request',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            425 => 'Too Early',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
        ];

        $this->status = $statusCode . (isset($knownCodes[$statusCode]) ? ' ' . $knownCodes[$statusCode] : '' );
    }
}
