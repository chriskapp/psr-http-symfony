<?php

namespace AppBundle\Psr;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriTargetInterface;
use Psr\Http\Message\StreamableInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\ServerBag;
use Symfony\Component\HttpFoundation\HeaderBag;
use Phly\Http\PhpInputStream;

class Request extends SymfonyRequest implements ServerRequestInterface
{
	use MessageTrait;

    /**
     * @var string
     */
    private $_method;

    /**
     * @var null|UriInterface
     */
    private $_uri;

    /**
     * Supported HTTP methods
     *
     * @var array
     */
    private $_validMethods = [
        'CONNECT',
        'DELETE',
        'GET',
        'HEAD',
        'OPTIONS',
        'POST',
        'PUT',
        'TRACE',
    ];

    /**
     * @var array
     */
    private $_attributes;

    /**
     * @var array
     */
    private $_bodyParams;

    /**
     * @var array
     */
    private $_cookieParams;

    /**
     * @var array
     */
    private $_fileParams;

    /**
     * @var array
     */
    private $_queryParams;

    /**
     * @var array
     */
    private $_serverParams;

    /**
     * @param array $serverParams Server parameters, typically from $_SERVER
     * @param array $fileParams Upload file information; should be in PHP's $_FILES format
     * @param null|string $uri URI for the request, if any.
     * @param null|string $method HTTP method for the request, if any.
     * @param string|resource|StreamableInterface $body Message body, if any.
     * @param array $headers Headers for the message, if any.
     * @throws InvalidArgumentException for any invalid value.
     */
    public function __construct(
        array $serverParams = [],
        array $fileParams = [],
        $uri = null,
        $method = null,
        $body = 'php://input',
        array $headers = []
    ) {
    	$body = $this->getStream($body);

        if (! $uri instanceof UriTargetInterface && ! is_string($uri) && null !== $uri) {
            throw new InvalidArgumentException(
                'Invalid URI provided; must be null, a string, or a Psr\Http\Message\UriTargetInterface instance'
            );
        }

        $this->validateMethod($method);

        if (! is_string($body) && ! is_resource($body) && ! $body instanceof StreamableInterface) {
            throw new InvalidArgumentException(
                'Body must be a string stream resource identifier, '
                . 'an actual stream resource, '
                . 'or a Psr\Http\Message\StreamableInterface implementation'
            );
        }

        if (is_string($uri)) {
            $uri = new Uri($uri);
        }

        $this->_method  = $method;
        $this->_uri     = $uri;
        $this->_stream  = ($body instanceof StreamableInterface) ? $body : new Stream($body, 'r');
        $this->_headers = $this->filterHeaders($headers);

        $this->_serverParams = $serverParams;
        $this->_fileParams   = $fileParams;
    }

    public function initialize(array $query = array(), array $request = array(), array $attributes = array(), array $cookies = array(), array $files = array(), array $server = array(), $content = null)
    {
        // Unfortunatly Symfony has as API the public properties headers etc. so
        // we can not override a method
        $this->request = new ParameterBag($this->_bodyParams);
        $this->query = new ParameterBag($this->_queryParams);
        $this->attributes = new ParameterBag($this->_attributes ?: array());
        $this->cookies = new ParameterBag($this->_cookieParams);
        $this->files = new FileBag($this->_fileParams);
        $this->server = new ServerBag($this->_serverParams);
        $this->headers = new HeaderBag($this->_headers);
    }

    public function __clone()
    {
    }

    /**
     * Proxy to receive the request method.
     *
     * This overrides the parent functionality to ensure the method is never
     * empty; if no method is present, it returns 'GET'.
     *
     * @return string
     */
    public function getMethod()
    {
        $method = $this->_method;
        if (empty ($method)) {
            return 'GET';
        }
        return $method;
    }

    /**
     * Set the request method.
     *
     * Unlike the regular Request implementation, the server-side
     * normalizes the method to uppercase to ensure consistency
     * and make checking the method simpler.
     *
     * This methods returns a new instance.
     *
     * @param string $method
     * @return self
     */
    public function withMethod($method)
    {
    	$method = strtoupper($method);
        $this->validateMethod($method);
        $new = clone $this;
        $new->_method = $method;
        return $new;
    }

    /**
     * Retrieves the URI instance.
     *
     * This method MUST return a UriInterface instance.
     *
     * @link http://tools.ietf.org/html/rfc3986#section-4.3
     * @return UriTargetInterface Returns a UriTargetInterface instance
     *     representing the URI of the request, if any.
     */
    public function getUri()
    {
        return $this->_uri;
    }

    /**
     * Create a new instance with the provided URI.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * new UriInterface instance.
     *
     * @link http://tools.ietf.org/html/rfc3986#section-4.3
     * @param UriTargetInterface $uri New request URI to use.
     * @return self
     */
    public function withUri(UriTargetInterface $uri)
    {
        $new = clone $this;
        $new->_uri = $uri;
        return $new;
    }

    /**
     * Validate the HTTP method
     *
     * @param null|string $method
     * @throws InvalidArgumentException on invalid HTTP method.
     */
    private function validateMethod($method)
    {
        if (null === $method) {
            return true;
        }

        if (! is_string($method)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP method; must be a string, received %s',
                (is_object($method) ? get_class($method) : gettype($method))
            ));
        }

        $method = strtoupper($method);

        if (! in_array($method, $this->_validMethods, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP method "%s" provided',
                $method
            ));
        }
    }

    /**
     * Retrieve server parameters.
     *
     * Retrieves data related to the incoming request environment,
     * typically derived from PHP's $_SERVER superglobal. The data IS NOT
     * REQUIRED to originate from $_SERVER.
     *
     * @return array
     */
    public function getServerParams()
    {
        return $this->_serverParams;
    }

    /**
     * Retrieve the upload file metadata.
     *
     * This method MUST return file upload metadata in the same structure
     * as PHP's $_FILES superglobal.
     *
     * These values MUST remain immutable over the course of the incoming
     * request. They SHOULD be injected during instantiation, such as from PHP's
     * $_FILES superglobal, but MAY be derived from other sources.
     *
     * @return array Upload file(s) metadata, if any.
     */
    public function getFileParams()
    {
        return $this->_fileParams;
    }

    /**
     * Retrieve cookies.
     *
     * Retrieves cookies sent by the client to the server.
     *
     * The data MUST be compatible with the structure of the $_COOKIE
     * superglobal.
     *
     * @return array
     */
    public function getCookieParams()
    {
        return $this->_cookieParams;
    }

    /**
     * Create a new instance with the specified cookies.
     *
     * The data IS NOT REQUIRED to come from the $_COOKIE superglobal, but MUST
     * be compatible with the structure of $_COOKIE. Typically, this data will
     * be injected at instantiation.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * updated cookie values.
     *
     * @param array $cookies Array of key/value pairs representing cookies.
     * @return self
     */
    public function withCookieParams(array $cookies)
    {
        $new = clone $this;
        $new->_cookieParams = $cookies;
        return $new;
    }

    /**
     * Retrieve query string arguments.
     *
     * Retrieves the deserialized query string arguments, if any.
     *
     * Note: the query params might not be in sync with the URL or server
     * params. If you need to ensure you are only getting the original
     * values, you may need to parse the composed URL or the `QUERY_STRING`
     * composed in the server params.
     *
     * @return array
     */
    public function getQueryParams()
    {
        return $this->_queryParams;
    }

    /**
     * Create a new instance with the specified query string arguments.
     *
     * These values SHOULD remain immutable over the course of the incoming
     * request. They MAY be injected during instantiation, such as from PHP's
     * $_GET superglobal, or MAY be derived from some other value such as the
     * URI. In cases where the arguments are parsed from the URI, the data
     * MUST be compatible with what PHP's parse_str() would return for
     * purposes of how duplicate query parameters are handled, and how nested
     * sets are handled.
     *
     * Setting query string arguments MUST NOT change the URL stored by the
     * request, nor the values in the server params.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * updated query string arguments.
     *
     * @param array $query Array of query string arguments, typically from
     *     $_GET.
     * @return self
     */
    public function withQueryParams(array $query)
    {
        $new = clone $this;
        $new->_queryParams = $query;
        return $new;
    }

    /**
     * Retrieve any parameters provided in the request body.
     *
     * If the request body can be deserialized to an array, this method MAY be
     * used to retrieve them.
     *
     * @return array The deserialized body parameters, if any.
     */
    public function getBodyParams()
    {
        return $this->_bodyParams;
    }

    /**
     * Create a new instance with the specified body parameters.
     *
     * These MAY be injected during instantiation from PHP's $_POST
     * superglobal. The data IS NOT REQUIRED to come from $_POST, but MUST be
     * an array. This method can be used during the request lifetime to inject
     * parameters discovered and/or deserialized from the request body; as an
     * example, if content negotiation determines that the request data is
     * a JSON payload, this method could be used to inject the deserialized
     * parameters.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * updated body parameters.
     *
     * @param array $params The deserialized body parameters.
     * @return self
     */
    public function withBodyParams(array $params)
    {
        $new = clone $this;
        $new->_bodyParams = $params;
        return $new;
    }

    /**
     * Retrieve attributes derived from the request.
     *
     * The request "attributes" may be used to allow injection of any
     * parameters derived from the request: e.g., the results of path
     * match operations; the results of decrypting cookies; the results of
     * deserializing non-form-encoded message bodies; etc. Attributes
     * will be application and request specific, and CAN be mutable.
     *
     * @return array Attributes derived from the request.
     */
    public function getAttributes()
    {
        return $this->_attributes;
    }

    /**
     * Retrieve a single derived request attribute.
     *
     * Retrieves a single derived request attribute as described in
     * getAttributes(). If the attribute has not been previously set, returns
     * the default value as provided.
     *
     * This method obviates the need for a hasAttribute() method, as it allows
     * specifying a default value to return if the attribute is not found.
     *
     * @see getAttributes()
     * @param string $attribute Attribute name.
     * @param mixed $default Default value to return if the attribute does not exist.
     * @return mixed
     */
    public function getAttribute($attribute, $default = null)
    {
        if (! array_key_exists($attribute, $this->_attributes)) {
            return $default;
        }
        return $this->_attributes[$attribute];
    }

    /**
     * Create a new instance with the specified derived request attribute.
     *
     * This method allows setting a single derived request attribute as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * updated attribute.
     *
     * @see getAttributes()
     * @param string $attribute The attribute name.
     * @param mixed $value The value of the attribute.
     * @return self
     */
    public function withAttribute($attribute, $value)
    {
        $new = clone $this;
        $new->_attributes[$attribute] = $value;
        return $new;
    }

    /**
     * Create a new instance that removes the specified derived request
     * attribute.
     *
     * This method allows removing a single derived request attribute as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that removes
     * the attribute.
     *
     * @see getAttributes()
     * @param string $attribute The attribute name.
     * @return self
     */
    public function withoutAttribute($attribute)
    {
        if (! isset($this->_attributes[$attribute])) {
            return $this;
        }
        $new = clone $this;
        unset($new->_attributes[$attribute]);
        return $new;
    }

    /**
     * Set the body stream
     *
     * @param string|resource|StreamableInterface $stream
     * @return void
     */
    private function getStream($stream)
    {
        if ($stream === 'php://input') {
            return new PhpInputStream();
        }
        if (! is_string($stream) && ! is_resource($stream) && ! $stream instanceof StreamableInterface) {
            throw new InvalidArgumentException(
                'Stream must be a string stream resource identifier, '
                . 'an actual stream resource, '
                . 'or a Psr\Http\Message\StreamableInterface implementation'
            );
        }
        if (! $stream instanceof StreamableInterface) {
            return new Stream($stream, 'r');
        }
        return $stream;
    }

    public static function createFromGlobals()
    {
    	$request = RequestFactory::fromGlobals();
    	$request->initialize();

    	return $request;
    }
}
