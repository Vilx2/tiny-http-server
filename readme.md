# TinyHttpServer - a minimalistic, embedded, near-zero-dependency HTTP server for PHP

## What is this?

As the title suggests, this is a very, very minimalistic HTTP server built entirely in pure PHP. 
The only dependency it has is the [PHP sockets extension](https://www.php.net/manual/en/book.sockets.php).

The code is compatible with PHP 8.1 and above, but that's mainly because of all the nice type declarations.
Delete those, and you can easily get it compatible with PHP 5 or even less.

The intended use case is internal monitoring and perhaps some very lightweight communication with long-running
PHP CLI (command-line) processes. You can relatively easily bolt this onto an existing project with minimal
changes.

## SECURITY WARNING - **DO <u>NOT</u> EXPOSE TINYHTTPSERVER TO THE INTERNET**.
You have been warned. There are no sanity checks. There's no
toughening of attack surfaces. You can easily crash your process with a malicious request. If you absolutely
have to, try at least putting an actual HTTP server like Apache or Nginx in front of it and proxy your
requests through that. And set as many sanity checks as you can there. Including (but not limited to) a
maximum request size.

## Does it support-
No. Probably not.

It's as minimalistic as you can get and still be called an "HTTP server".
The one thing it does support is having an `Expect: 100-continue` header in the request, but beyond that
you're on your own. If you need something more robust and feature-complete, take a look at [ReactPHP](https://reactphp.org/). 

That said, there's quite a few features that you can actually implement yourself in the request handler, if you need to.
Here's a non-exhaustive list of some things you can and cannot do.

#### You can't do this without modifying TinyHTTPServer itself:
* Websockets
* Processing multiple requests in parallel (async)
  * But the actual receiving/sending of data over the network will be handled in parallel by TinyHTTPServer.
    It's just the handling that is one-request-at-a-time.
* Streaming requests or responses
* HTTPS
* HTTP 2 or 3
* Keep-alive

#### You can do this in your own handler, but it's not supported out of the box:
* Request body parsing
* Query string parsing
* Caching
* Cookies
* Sessions
* Authorization
* Routing
* File serving
* CORS
* Virtual hosts
* `HEAD` and `OPTIONS` request methods
* `Content-encoding`
* `Transfer-encoding`
* And all other HTTP headers except for the `Expect: 100-continue`.

## How do you use it?

The basic usage pattern looks like this:

First you create the server object and specify a request handler.

```php
$server = new TinyHttpServer(function( TinyHttpServerRequest $request, TinyHttpServerResponse $response) {
    // Doing nothing here will simply return an HTTP 200.
});
```

Then you start it:

```php
$server->start();
```

And then you periodically (read: often in your main loop) call:

```php
$server->process();
```

Standalone PHP processes often also feature calls to [`sleep()`](https://www.php.net/manual/en/function.sleep.php)
or [`usleep()`](https://www.php.net/manual/en/function.usleep.php).

These should be replaced with  `$server->process(123.456)` where `123.456` is sleep time in seconds. Fractional
seconds are supported. This will put the process to sleep (uses 0% CPU), but will also process any incoming
requests, should they appear.

## Full reference

### class TinyHttpServer

#### public function __construct(callable \$handler, ?string \$ip = null, ?int \$port = null)

Creates the server object.

* The request handler `$handler` is mandatory and will be called for every incoming request. It will be passed two
  parameters: `TinyHttpServerRequest $request` and `TinyHttpServerResponse $response`. These allow you to inspect the
request headers and body; and set the response status, headers and body. See below for details.
* The `$ip` parameter specifies which IP addresses the server will listen to for incoming connections.
IPv4 is supported; IPv6 _should_ be supported, but has not been tested. For IPv6 enclose the IP address in square
brackets - like `"[fe80::1]"`. If this parameter is `null`, TinyHttpServer will check for the presence of `TINYHTTP_IP`
environment variable and use that. If that too is absent, it will use `"0.0.0.0"` as the IP address, which will listen
on ALL IPv4 addresses.
* The `$port` parameter specifies which port the server will listen to for incoming connections. If this parameter is
`null`, TinyHttpServer will check for the presence of `TINYHTTP_PORT` environment variable and use that. If that too is
absent, it will use `8888` as the port.

#### public function start(): void

Starts listening for incoming connections.

#### public function stop(): void

Stops listening for incoming connections and immediately closes any existing connections. Note: normally you do not need
to call this. When you exit your process, the operating system will do this automatically and efficiently as part of the
process cleanup.

#### public function process(float \$min_time = 0, ?float \$max_time = null): void

This function needs to be called often while the server is running. It checks for incoming connections and handles any
incoming and outgoing data. When a request is received, it will call the handler and send the response. It has two
parameters which affect how long it will run.

* `$min_time` is the **minimum** time (in seconds, fractions supported) how long this function will take. If there is
  nothing to do, the function will sleep until either the time runs out, or some network activity happens. Specifying
  `0` or negative values means "run as long as there is something to do". In this case the function will return once
  it's out of work.
* `$max_time` is the **maximum** time (in seconds, fractions supported) that the function will take. Note that a small
  overshoot is still possible, and if the handler takes forever, then all bets are off. Specifying `0` or negative 
  values will cause the function to do just one quick pass over all the new network activity before returning, so it
  will still do _some_ processing, it just won't keep looping until it runs out of work. Specifying `null` means
  "no limit", so the function will only return once it has run out of work to do and the minimum time has elapsed.

If `$max_time` is less than `$min_time`, the results are unpredictable (but it won't crash). 

### class TinyHttpServerRequest

Contains all the data about an incoming HTTP request. In your handler you can check this to decide what to do.

* `string $method` - the HTTP method (GET, POST, PUT, etc.) that was used
* `string $path` - the path that was submitted, including the query string
* `string[][] $headers` - parsed headers. The first index is the header name, converted to lowercase. The second index
  is increasing numeric. This is useful for repeated headers, so that you can get all values, but also for non-repeated
  headers you still need to access them as `$headers['header-name'][0]`.
* `?string $body` - the body of the request, if any.
* `string $peer` - the IP address and port of the incoming request.

### class TinyHttpServerResponse

Contains all the data that will be sent back as a response. You set these properties in your handler.

* `string $status = '200 OK'` - full status string in format `<code> <reason>`.
* `string[][] $headers` - headers to be sent back.
* `?string $body` - Body contents, if any, to be sent back

There's also a helper method:

#### public function setHttpStatus(int $statusCode)

This just sets the same `$status` property as above, but for the commonly known response codes it automatically adds the
reason string. So:

```php
$response->setHttpStatus(304);
```

is the same as

```php
$response->status = '304 Not Modified';
```