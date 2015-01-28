<?php

namespace AppBundle\Psr;

use Phly\Http\ServerRequestFactory;

class RequestFactory extends ServerRequestFactory
{
    public static function fromGlobals(
        array $server = null,
        array $query = null,
        array $body = null,
        array $cookies = null,
        array $files = null
    ) {
        $server  = self::normalizeServer($server ?: $_SERVER);
        $files   = $files   ?: $_FILES;
        $headers = self::marshalHeaders($server);
        $request = new Request(
            $server,
            $files,
            self::marshalUriFromServer($server, $headers),
            self::get('REQUEST_METHOD', $server, 'GET'),
            'php://input',
            $headers
        );

        return $request
            ->withCookieParams($cookies ?: $_COOKIE)
            ->withQueryParams($query ?: $_GET)
            ->withBodyParams($body ?: $_POST);
    }
}
