<?php

/**
 * Phower Http
 *
 * @version 1.0.0
 * @link https://github.com/phower/http Public Git repository
 * @copyright (c) 2015-2016, Pedro Ferreira <https://phower.com>
 * @license https://opensource.org/licenses/MIT MIT
 */

namespace Phower\Http;

use Psr\Http\Message\UriInterface;
use Phower\Http\Exception;

/**
 * Value object representing a URI.
 *
 * This interface is meant to represent URIs according to RFC 3986 and to
 * provide methods for most common operations. Additional functionality for
 * working with URIs can be provided on top of the interface or externally.
 * Its primary use is for HTTP requests, but may also be used in other
 * contexts.
 *
 * Instances of this interface are considered immutable; all methods that
 * might change state MUST be implemented such that they retain the internal
 * state of the current instance and return an instance that contains the
 * changed state.
 *
 * Typically the Host header will be also be present in the request message.
 * For server-side requests, the scheme will typically be discoverable in the
 * server parameters.
 *
 * @link http://tools.ietf.org/html/rfc3986 (the URI specification)
 *
 * @author Pedro Ferreira <pedro@phower.com>
 */
class Uri implements UriInterface
{

    /**
     * @var array
     */
    protected $standardSchemePorts = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * @var string
     */
    private $scheme = '';

    /**
     * @var string
     */
    private $userInfo = '';

    /**
     * @var string
     */
    private $host = '';

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $path = '';

    /**
     * @var string
     */
    private $query = '';

    /**
     * @var string
     */
    private $fragment = '';

    /**
     * @var string|null
     */
    private $uriString;

    /**
     * Class constructor
     *
     * @param string $uri
     * @throws Exception\InvalidArgumentException
     */
    public function __construct($uri = '')
    {
        if (!is_string($uri)) {
            $type = is_object($uri) ? get_class($uri) : gettype($uri);
            $message = sprintf('Argument "uri" must be a string; received "%s".', $type);
            throw new Exception\InvalidArgumentException($message);
        }

        if (!empty($uri)) {
            if (false === $parts = parse_url($uri)) {
                $message = 'Argument "uri" is not a valid URI string.';
                throw new Exception\InvalidArgumentException($message);
            }

            $this->scheme = isset($parts['scheme']) ? $this->filterScheme($parts['scheme']) : '';
            $this->userInfo = isset($parts['user']) ? $parts['user'] : '';
            $this->host = isset($parts['host']) ? $parts['host'] : '';
            $this->port = isset($parts['port']) ? $parts['port'] : null;
            $this->path = isset($parts['path']) ? $this->filterPath($parts['path']) : '';
            $this->query = isset($parts['query']) ? $this->filterQuery($parts['query']) : '';
            $this->fragment = isset($parts['fragment']) ? $this->filterFragment($parts['fragment']) : '';

            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }

    /**
     * Create new Uri instance from current PHP globals.
     *
     * @param array $server
     * @return Uri
     */
    public static function createFromGlobals(array $server = null)
    {
        if (null === $server && isset($_SERVER)) {
            $server = $_SERVER;
        }

        $uri = new Uri('');

        $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        $uri = $uri->withScheme($scheme);

        $port = null;
        if (isset($server['HTTP_HOST'])) {
            $parts = explode(':', $server['HTTP_HOST']);
            $uri = $uri->withHost($parts[0]);
            if (isset($parts[1])) {
                $port = (int) $parts[1];
            }
        } elseif (isset($server['SERVER_NAME'])) {
            $uri = $uri->withHost($server['SERVER_NAME']);
        } elseif (isset($server['SERVER_ADDR'])) {
            $uri = $uri->withHost($server['SERVER_ADDR']);
        }

        if (null === $port && isset($server['SERVER_PORT'])) {
            $port = (int) $server['SERVER_PORT'];
        }

        if ($port) {
            $uri = $uri->withPort($port);
        }

        $query = null;
        if (isset($server['REQUEST_URI'])) {
            $requestUriParts = explode('?', $server['REQUEST_URI']);
            $uri = $uri->withPath($requestUriParts[0]);
            if (isset($requestUriParts[1])) {
                $query = $requestUriParts[1];
            }
        }

        if (null === $query && isset($server['QUERY_STRING'])) {
            $query = $server['QUERY_STRING'];
        }

        if ($query) {
            $uri = $uri->withQuery($query);
        }

        return $uri;
    }

    /**
     * Class clone
     */
    public function __clone()
    {
        $this->uriString = null;
    }

    /**
     * Filter scheme
     *
     * @param string $scheme
     * @return string
     * @throws Exception\InvalidArgumentException
     */
    private function filterScheme($scheme)
    {
        $scheme = trim(strtolower($scheme));
        $scheme = preg_replace('#:(//)?$#', '', $scheme);

        if (empty($scheme)) {
            return '';
        }

        if (!array_key_exists($scheme, $this->standardSchemePorts)) {
            $schemes = implode(', ', array_keys($this->standardSchemePorts));
            $message = sprintf('Unsupported scheme "%s"; must be one of "%s".', $scheme, $schemes);
            throw new Exception\InvalidArgumentException($message);
        }

        return $scheme;
    }

    /**
     * Filter path
     *
     * @param string $path
     * @return string
     */
    private function filterPath($path)
    {
        $pattern = '/(?:[^a-zA-Z0-9_\-\.~:@&=\+\$,\/;%]+|%(?![A-Fa-f0-9]{2}))/';
        $callback = [$this, 'urlEncodeChar'];
        return preg_replace_callback($pattern, $callback, $path);
    }

    /**
     * Url encode char
     *
     * @param array $matches
     * @return string
     */
    private function urlEncodeChar(array $matches)
    {
        return rawurlencode($matches[0]);
    }

    /**
     * Filter query
     *
     * @param string $query
     * @return string
     */
    private function filterQuery($query)
    {
        if (strpos($query, '?') === 0) {
            $query = substr($query, 1);
        }

        $parts = explode('&', $query);

        foreach ($parts as $i => $part) {
            list($key, $value) = $this->splitQueryValue($part);

            if ($value === null) {
                $parts[$i] = $this->filterQueryOrFragment($key);
                continue;
            }

            $key = $this->filterQueryOrFragment($key);
            $value = $this->filterQueryOrFragment($value);
            $parts[$i] = sprintf('%s=%s', $key, $value);
        }

        return implode('&', $parts);
    }

    /**
     * Split query value
     *
     * @param string $value
     * @return array
     */
    private function splitQueryValue($value)
    {
        $data = explode('=', $value, 2);

        if (1 === count($data)) {
            $data[] = null;
        }

        return $data;
    }

    /**
     * Filter query or fragment
     *
     * @param string $value
     * @return string
     */
    private function filterQueryOrFragment($value)
    {
        $pattern = '/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/';
        $callback = [$this, 'urlEncodeChar'];
        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Filter fragment
     *
     * @param string $fragment
     * @return string
     */
    private function filterFragment($fragment)
    {
        if (strpos($fragment, '#') === 0) {
            $fragment = substr($fragment, 1);
        }

        return $this->filterQueryOrFragment($fragment);
    }

    /**
     * Retrieve the scheme component of the URI.
     *
     * If no scheme is present, this method MUST return an empty string.
     *
     * The value returned MUST be normalized to lowercase, per RFC 3986
     * Section 3.1.
     *
     * The trailing ":" character is not part of the scheme and MUST NOT be
     * added.
     *
     * @see    https://tools.ietf.org/html/rfc3986#section-3.1
     * @return string The URI scheme.
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * Retrieve the authority component of the URI.
     *
     * If no authority information is present, this method MUST return an empty
     * string.
     *
     * The authority syntax of the URI is:
     *
     * <pre>
     * [user-info@]host[:port]
     * </pre>
     *
     * If the port component is not set or is the standard port for the current
     * scheme, it SHOULD NOT be included.
     *
     * @see    https://tools.ietf.org/html/rfc3986#section-3.2
     * @return string The URI authority, in "[user-info@]host[:port]" format.
     */
    public function getAuthority()
    {
        if (empty($this->host)) {
            return '';
        }

        $authority = $this->host;

        if (!empty($this->userInfo)) {
            $authority = sprintf('%s@%s', $this->userInfo, $authority);
        }

        if ($this->isNonStandardPort()) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    /**
     * Is non standard port
     *
     * @return boolean
     */
    private function isNonStandardPort()
    {
        if (!$this->scheme) {
            return true;
        }

        if (!$this->host || !$this->port) {
            return false;
        }

        $isStandardScheme = isset($this->standardSchemePorts[$this->scheme]);
        $isStandardPort = $isStandardScheme ? $this->port === $this->standardSchemePorts[$this->scheme] : false;

        return !$isStandardScheme || !$isStandardPort;
    }

    /**
     * Retrieve the user information component of the URI.
     *
     * If no user information is present, this method MUST return an empty
     * string.
     *
     * If a user is present in the URI, this will return that value;
     * additionally, if the password is also present, it will be appended to the
     * user value, with a colon (":") separating the values.
     *
     * The trailing "@" character is not part of the user information and MUST
     * NOT be added.
     *
     * @return string The URI user information, in "username[:password]" format.
     */
    public function getUserInfo()
    {
        return $this->userInfo;
    }

    /**
     * Retrieve the host component of the URI.
     *
     * If no host is present, this method MUST return an empty string.
     *
     * The value returned MUST be normalized to lowercase, per RFC 3986
     * Section 3.2.2.
     *
     * @see    http://tools.ietf.org/html/rfc3986#section-3.2.2
     * @return string The URI host.
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Retrieve the port component of the URI.
     *
     * If a port is present, and it is non-standard for the current scheme,
     * this method MUST return it as an integer. If the port is the standard port
     * used with the current scheme, this method SHOULD return null.
     *
     * If no port is present, and no scheme is present, this method MUST return
     * a null value.
     *
     * If no port is present, but a scheme is present, this method MAY return
     * the standard port for that scheme, but SHOULD return null.
     *
     * @return null|int The URI port.
     */
    public function getPort()
    {
        return $this->isNonStandardPort() ? $this->port : null;
    }

    /**
     * Retrieve the path component of the URI.
     *
     * The path can either be empty or absolute (starting with a slash) or
     * rootless (not starting with a slash). Implementations MUST support all
     * three syntaxes.
     *
     * Normally, the empty path "" and absolute path "/" are considered equal as
     * defined in RFC 7230 Section 2.7.3. But this method MUST NOT automatically
     * do this normalization because in contexts with a trimmed base path, e.g.
     * the front controller, this difference becomes significant. It's the task
     * of the user to handle both "" and "/".
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.3.
     *
     * As an example, if the value should include a slash ("/") not intended as
     * delimiter between path segments, that value MUST be passed in encoded
     * form (e.g., "%2F") to the instance.
     *
     * @see    https://tools.ietf.org/html/rfc3986#section-2
     * @see    https://tools.ietf.org/html/rfc3986#section-3.3
     * @return string The URI path.
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Retrieve the query string of the URI.
     *
     * If no query string is present, this method MUST return an empty string.
     *
     * The leading "?" character is not part of the query and MUST NOT be
     * added.
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.4.
     *
     * As an example, if a value in a key/value pair of the query string should
     * include an ampersand ("&") not intended as a delimiter between values,
     * that value MUST be passed in encoded form (e.g., "%26") to the instance.
     *
     * @see    https://tools.ietf.org/html/rfc3986#section-2
     * @see    https://tools.ietf.org/html/rfc3986#section-3.4
     * @return string The URI query string.
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Retrieve the fragment component of the URI.
     *
     * If no fragment is present, this method MUST return an empty string.
     *
     * The leading "#" character is not part of the fragment and MUST NOT be
     * added.
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.5.
     *
     * @see    https://tools.ietf.org/html/rfc3986#section-2
     * @see    https://tools.ietf.org/html/rfc3986#section-3.5
     * @return string The URI fragment.
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * Return an instance with the specified scheme.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified scheme.
     *
     * Implementations MUST support the schemes "http" and "https" case
     * insensitively, and MAY accommodate other schemes if required.
     *
     * An empty scheme is equivalent to removing the scheme.
     *
     * @param  string $scheme The scheme to use with the new instance.
     * @return self A new instance with the specified scheme.
     * @throws \InvalidArgumentException for invalid or unsupported schemes.
     */
    public function withScheme($scheme)
    {
        $scheme = $this->filterScheme($scheme);

        if ($scheme === $this->scheme) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->scheme = $scheme;

        return $clone;
    }

    /**
     * Return an instance with the specified user information.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified user information.
     *
     * Password is optional, but the user information MUST include the
     * user; an empty string for the user is equivalent to removing user
     * information.
     *
     * @param  string      $user     The user name to use for authority.
     * @param  null|string $password The password associated with $user.
     * @return self A new instance with the specified user information.
     */
    public function withUserInfo($user, $password = null)
    {
        $info = $user;

        if ($password) {
            $info .= ':' . $password;
        }

        if ($info === $this->userInfo) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->userInfo = $info;

        return $clone;
    }

    /**
     * Return an instance with the specified host.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified host.
     *
     * An empty host value is equivalent to removing the host.
     *
     * @param  string $host The hostname to use with the new instance.
     * @return self A new instance with the specified host.
     * @throws \InvalidArgumentException for invalid hostnames.
     */
    public function withHost($host)
    {
        if ($host === $this->host) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->host = $host;

        return $clone;
    }

    /**
     * Return an instance with the specified port.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified port.
     *
     * Implementations MUST raise an exception for ports outside the
     * established TCP and UDP port ranges.
     *
     * A null value provided for the port is equivalent to removing the port
     * information.
     *
     * @param  null|int $port The port to use with the new instance; a null value
     *     removes the port information.
     * @return self A new instance with the specified port.
     * @throws \InvalidArgumentException for invalid ports.
     */
    public function withPort($port)
    {
        if (!(is_integer($port) || (is_string($port) && is_numeric($port)))) {
            $type = is_object($port) ? get_class($port) : gettype($port);
            $message = sprintf('Invalid port type "%s"; it must be an integer or integer string', $type);
            throw new Exception\InvalidArgumentException($message);
        }

        $port = (int) $port;

        if ($port === $this->port) {
            return clone $this;
        }

        if ($port < 1 || $port > 65535) {
            $message = sprintf('Invalid port "%d" value; it must be a valid TCP/UDP port.', $port);
            throw new Exception\InvalidArgumentException($message);
        }

        $clone = clone $this;
        $clone->port = $port;

        return $clone;
    }

    /**
     * Return an instance with the specified path.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified path.
     *
     * The path can either be empty or absolute (starting with a slash) or
     * rootless (not starting with a slash). Implementations MUST support all
     * three syntaxes.
     *
     * If the path is intended to be domain-relative rather than path relative then
     * it must begin with a slash ("/"). Paths not starting with a slash ("/")
     * are assumed to be relative to some base path known to the application or
     * consumer.
     *
     * Users can provide both encoded and decoded path characters.
     * Implementations ensure the correct encoding as outlined in getPath().
     *
     * @param  string $path The path to use with the new instance.
     * @return self A new instance with the specified path.
     * @throws \InvalidArgumentException for invalid paths.
     */
    public function withPath($path)
    {
        if (!is_string($path)) {
            throw new Exception\InvalidArgumentException('Invalid path provided; it must be a string.');
        }

        if (strpos($path, '?') !== false) {
            throw new Exception\InvalidArgumentException('Invalid path provided; it must not contain a query string.');
        }

        if (strpos($path, '#') !== false) {
            throw new Exception\InvalidArgumentException('Invalid path provided; must not contain a URI fragment.');
        }

        $path = $this->filterPath($path);

        if ($path === $this->path) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    /**
     * Return an instance with the specified query string.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified query string.
     *
     * Users can provide both encoded and decoded query characters.
     * Implementations ensure the correct encoding as outlined in getQuery().
     *
     * An empty query string value is equivalent to removing the query string.
     *
     * @param  string $query The query string to use with the new instance.
     * @return self A new instance with the specified query string.
     * @throws \InvalidArgumentException for invalid query strings.
     */
    public function withQuery($query)
    {
        if (!is_string($query)) {
            throw new Exception\InvalidArgumentException('Query string must be a string.');
        }

        if (strpos($query, '#') !== false) {
            throw new Exception\InvalidArgumentException('Query string must not include a URI fragment.');
        }

        $query = $this->filterQuery($query);

        if ($query === $this->query) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->query = $query;

        return $clone;
    }

    /**
     * Return an instance with the specified URI fragment.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified URI fragment.
     *
     * Users can provide both encoded and decoded fragment characters.
     * Implementations ensure the correct encoding as outlined in getFragment().
     *
     * An empty fragment value is equivalent to removing the fragment.
     *
     * @param  string $fragment The fragment to use with the new instance.
     * @return self A new instance with the specified fragment.
     */
    public function withFragment($fragment)
    {
        if (!is_string($fragment)) {
            throw new Exception\InvalidArgumentException('Fragment must be a string.');
        }

        $fragment = $this->filterFragment($fragment);

        if ($fragment === $this->fragment) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->fragment = $fragment;

        return $clone;
    }

    /**
     * Return the string representation as a URI reference.
     *
     * Depending on which components of the URI are present, the resulting
     * string is either a full URI or relative reference according to RFC 3986,
     * Section 4.1. The method concatenates the various components of the URI,
     * using the appropriate delimiters:
     *
     * - If a scheme is present, it MUST be suffixed by ":".
     * - If an authority is present, it MUST be prefixed by "//".
     * - The path can be concatenated without delimiters. But there are two
     *   cases where the path has to be adjusted to make the URI reference
     *   valid as PHP does not allow to throw an exception in __toString():
     *     - If the path is rootless and an authority is present, the path MUST
     *       be prefixed by "/".
     *     - If the path is starting with more than one "/" and no authority is
     *       present, the starting slashes MUST be reduced to one.
     * - If a query is present, it MUST be prefixed by "?".
     * - If a fragment is present, it MUST be prefixed by "#".
     *
     * @see    http://tools.ietf.org/html/rfc3986#section-4.1
     * @return string
     */
    public function __toString()
    {
        if ($this->uriString !== null) {
            return $this->uriString;
        }

        $this->uriString = '';

        if (!empty($this->scheme)) {
            $this->uriString .= sprintf('%s://', $this->scheme);
        }

        if (!empty($authority = $this->getAuthority())) {
            $this->uriString .= $authority;
        }

        if ($this->path) {
            if (empty($this->path) || '/' !== substr($this->path, 0, 1)) {
                $this->path = '/' . $this->path;
            }

            $this->uriString .= $this->path;
        }

        if ($this->query) {
            $this->uriString .= sprintf('?%s', $this->query);
        }

        if ($this->fragment) {
            $this->uriString .= sprintf('#%s', $this->fragment);
        }

        return $this->uriString;
    }
}
