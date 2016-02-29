<?php declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: mbrzuchalski
 * Date: 23.02.16
 * Time: 13:55
 */
namespace Madkom\Uri;

use Madkom\Uri\Component\Authority;
use Madkom\Uri\Component\Authority\Host\IPv4;
use Madkom\Uri\Component\Authority\Host\IPv6;
use Madkom\Uri\Component\Authority\Host\Name;
use Madkom\Uri\Component\Authority\UserInfo;
use Madkom\Uri\Component\Fragment;
use Madkom\Uri\Component\Path;
use Madkom\Uri\Component\Query;
use Madkom\Uri\Component\Query\Parameter;
use Madkom\Uri\Exception\MalformedAuthorityParseUriException;
use Madkom\Uri\Exception\MissingSchemeParseUriException;
use Madkom\Uri\Exception\ParseUriException;
use Madkom\Uri\Scheme\Http;
use Madkom\Uri\Scheme\Https;
use Madkom\Uri\Scheme\Isbn;
use Madkom\Uri\Scheme\Scheme;
use UnexpectedValueException;

/**
 * Class Parser
 * @package Madkom\Uri
 * @author Michał Brzuchalski <m.brzuchalski@madkom.pl>
 */
class UriFactory
{
    /**
     * Valid characters (taken from rfc2396/3986)
     */
    const RFC2396_DIGIT = "0-9";
    const RFC2396_LOWALPHA = "a-z";
    const RFC2396_UPALPHA = "A-Z";
    const RFC2396_ALPHA = self::RFC2396_LOWALPHA . self::RFC2396_UPALPHA;
    const RFC2396_ALPHANUM = self::RFC2396_DIGIT . self::RFC2396_ALPHA;
    const RFC3986_UNRESERVED = self::RFC2396_ALPHANUM . "\\-\\._~";
    const RFC3986_SUBDELIMS = "!$&'\\(\\)\\*\\+,;=";
    const RFC3986_REG_NAME = self::RFC3986_UNRESERVED . self::RFC3986_SUBDELIMS . "%";
    const RFC3986_PCHAR = self::RFC3986_UNRESERVED . self::RFC3986_SUBDELIMS . ":@%";
    const RFC3986_SEGMENT = self::RFC3986_PCHAR;
    const RFC3986_PATH_SEGMENTS = self::RFC3986_SEGMENT . "\\/";
    const RFC3986_SSP = self::RFC3986_PCHAR . "\\?\\/";
    const RFC3986_HOST = self::RFC3986_REG_NAME . "\\[\\]";
    const RFC3986_USERINFO = self::RFC3986_REG_NAME . ":";

    /**
     * Regular expression for parsing URIs.
     *
     * Taken from RFC 2396, Appendix B.
     * This expression doesn't parse IPv6 addresses.
     */
    const URI_REGEXP = "^((?<scheme>[^:\\/?#]+):)?((\\/\\/(?<authority>[^\\/?#]*))?(?<path>[^?#]*)(\\?(?<query>[^#]*))?)?" .
        "(#(?<fragment>.*))?";

    // Drop numeric, and  "+-." for now
    // Validation of character set is done by isValidAuthority
    //const AUTHORITY_CHARS_REGEX = "a-zA-Z0-9\\-\\."; // allows for IPV4 but not IPV6
    const AUTHORITY_CHARS_REGEX = "((?=[a-z0-9-]{1,63}\\.)[a-z0-9]+(\\-[a-z0-9]+)*\\.)*[a-z]{2,63}"; // allows only for IPV4
    const IPV6_REGEX = "[0-9a-fA-F:]+"; // do this as separate match because : could cause ambiguity with port prefix
    const INNER_OCTET_REGEX = "25[0-5]|2[0-4]\\d|1\\d{2}|[1-9]\\d|[1-9]";
    const MIDDLE_OCTET_REGEX = "25[0-5]|2[0-4]\\d|1\\d{2}|[1-9]\\d|\\d";
    const IPV4_REGEX = "(((" . self::INNER_OCTET_REGEX. ")\\.)((" . self::MIDDLE_OCTET_REGEX . ")\\.){2}" .
        "(" . self::INNER_OCTET_REGEX . "))";

    // TODO: check every AAUTHORITY | USERINFO prefixed constants
    // userinfo    = *( unreserved / pct-encoded / sub-delims / ":" )
    // unreserved    = ALPHA / DIGIT / "-" / "." / "_" / "~"
    // sub-delims    = "!" / "$" / "&" / "'" / "(" / ")" / "*" / "+" / "," / ";" / "="
    // We assume that password has the same valid chars as user info
    const USERINFO_CHARS_REGEX = "[a-zA-Z0-9%\\-\\._~!$&'\\(\\)\\*\\+,;=]";
    // since neither ':' nor '@' are allowed chars, we don't need to use non-greedy matching
    const USERINFO_FIELD_REGEX = "(?<user>" . self::USERINFO_CHARS_REGEX . "+):" . // Name at least one character
        "(?<password>" . self::USERINFO_CHARS_REGEX . "*)"; // password may be absent
    const AUTHORITY_REGEX = "((?<userInfo>" . self::USERINFO_FIELD_REGEX . ")@)?" .
        "((?<ipv4>" . self::IPV4_REGEX . ")|\\[(?<ipv6>" . self::IPV6_REGEX . ")\\]|(?<hostname>" .
    self::AUTHORITY_CHARS_REGEX . "))(:(?<port>\\d*))?";

    // Path delimiter
    const PATH_DELIMITER = '/';

    // Match query string
    const QUERY_NAME_MATCH = "[" . self::RFC3986_UNRESERVED . "!$\\(\\)\\[\\]\\*\\+,;:@%]+";
    const QUERY_VALUE_MATCH = "[" . self::RFC3986_UNRESERVED . "!$\\(\\)\\[\\]\\*\\+,;:@%]*";
    const QUERY_MATCH_REGEX = "/^(" . self::QUERY_NAME_MATCH . "(=" . self::QUERY_VALUE_MATCH . ")?" .
        "(&[\\w-]+(=[\\w-]*)?)*)?[&]?$/";
    // Match query string parameters, RFC: *( pchar / "/" / "?" )
    const QUERY_PARAMETER_MATCH = "((?<name>" . self::QUERY_NAME_MATCH . ")(=(?<value>" . self::QUERY_VALUE_MATCH . "))?)";

    // Parse mode where parameter duplicate replaces previous parameter
    const MODE_QUERY_DUPLICATE_LAST       = 0;
    const MODE_QUERY_DUPLICATE_WITH_COLON = 1;
    const MODE_QUERY_DUPLICATE_AS_ARRAY   = 2;
    const MODE_QUERY_SEMICOLON_DELIMITER  = 4;

    protected static $schemes = [
        Http::PROTOCOL => Http::class,
        Https::PROTOCOL => Https::class,
        Isbn::PROTOCOL => Isbn::class,
    ];

    /**
     * @var int Parsing mode (default: duplicate params set last)
     */
    protected $mode = self::MODE_QUERY_DUPLICATE_LAST;

    /**
     * Create new Uri from string
     * @param string $uriString
     * @param Scheme $defaultScheme
     * @return Uri
     * @throws MissingSchemeParseUriException When scheme missing in uri string
     * @throws ParseUriException When unable to match uri regex
     */
    public function createUri(string $uriString, Scheme $defaultScheme = null) : Uri
    {
        if (preg_match("/" . self::URI_REGEXP . "/", $uriString, $matches)) {
            $scheme = $defaultScheme;
            if ($matches['scheme']) {
                if (array_key_exists($matches['scheme'], self::$schemes)) {
                    $schemeName = self::$schemes[$matches['scheme']];
                    $scheme = new $schemeName();
                }
            }
            if (null === $scheme) {
                throw new MissingSchemeParseUriException("Malformed uri string, invalid scheme given: {$uriString}");
            }
            if (array_key_exists('authority', $matches) && $matches['authority']) {
                $authority = $this->parseAuthority($matches['authority']);
            }
            $path = $this->parsePath($matches['path']);
            if (array_key_exists('query', $matches) && $matches['query']) {
                $query = $this->parseQuery($matches['query'], $this->mode);
            }
            if (array_key_exists('fragment', $matches) && $matches['fragment']) {
                $fragment = new Fragment($matches['fragment']);
            }

            return new Uri($scheme, $authority ?? null, $path, $query ?? null, $fragment ?? null);
        }

        throw new ParseUriException("Malformed uri string, unable to parse, given: {$uriString}");
    }

    /**
     * Parse authority string into Authority
     * @param string $authorityString
     * @return Authority
     * @throws MalformedAuthorityParseUriException On non-matching string
     */
    protected function parseAuthority(string $authorityString) : Authority
    {
        if (preg_match("/^" . self::AUTHORITY_REGEX . "$/", $authorityString, $match)) {
            if ($match['ipv4']) {
                $host = new IPv4($match['ipv4']);
            } elseif ($match['ipv6']) {
                $host = new IPv6($match['ipv6']);
            } else {
                $host = new Name($match['hostname']);
            }
            if ($match['userInfo']) {
                $userInfo = new UserInfo($match['user'], $match['password']);
            }

            return new Authority($host, empty($match['port']) ? null : intval($match['port']), $userInfo ?? null);
        }

        throw new MalformedAuthorityParseUriException("Malformed authority string, given: {$authorityString}");
    }

    /**
     * Parse path string into Path
     * @param string $pathString Path string to parse
     * @return Path
     */
    protected function parsePath(string $pathString) : Path
    {
        $segments = explode(self::PATH_DELIMITER, ltrim($pathString, self::PATH_DELIMITER));

        return new Path($segments);
    }

    /**
     * Parse query string into Query
     * @param string $queryString String with query to parse
     * @param int $mode Parse mode {@see self::MODE_QUERY_DUPLICATE_LAST}
     * @throws UnexpectedValueException On unsupported mode
     * @return Query
     */
    protected function parseQuery(string $queryString, int $mode = self::MODE_QUERY_DUPLICATE_LAST) : Query
    {
        $query = new Query();

        // When duplicate detected replace with duplicate value
        if (!(self::MODE_QUERY_DUPLICATE_AS_ARRAY & $mode) && !(self::MODE_QUERY_DUPLICATE_WITH_COLON & $mode)) {
            parse_str($queryString, $parameters);
            foreach ($parameters as $name => $value) {
                $query->add(new Parameter($name, $value));
            }

            return $query;
        }

        // When duplicate detected turn value into an array or concatenated with colon when duplicate exists
        if (preg_match_all("/" . self::QUERY_PARAMETER_MATCH . "/", $queryString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match['name'];
                $value = $match['value'];
                if (preg_match('/^[^\[\]]+(\[[^\]]*\])+$/', $name, $d) && (self::MODE_QUERY_DUPLICATE_AS_ARRAY & $mode)) {
                    parse_str($match[1], $parsedParameter);
                    if (sizeof($parsedParameter) === 1) {
                        $name = key($parsedParameter);
                        $value = reset($parsedParameter);
                    }
                }
                // If parameter already exists append value otherwise add parameter to Query
                if ($query->exists(function (Parameter $parameter) use ($name) {
                    return $parameter->getName() == $name;
                })
                ) {
                    /** @var Parameter $parameter */
                    foreach ($query as $parameter) {
                        if ($parameter->getName() == $name) {
                            $currentValue = $parameter->getValue();
                            // Decode urlencoded value
                            $value = is_array($value) ? $this->decodeUrlArrayValue($value) : $this->decodeUrlValue($value);
                            switch (true) {
                                // When duplicate detected turn value into an array
                                case self::MODE_QUERY_DUPLICATE_AS_ARRAY & $mode:
                                    // Decide how to merge existing value with parsed one
                                    if (is_array($currentValue) && is_array($value)) {
                                        $currentValue = array_merge($currentValue, $value);
                                    } elseif (is_array($currentValue) && !is_array($value)) {
                                        $currentValue[] = $value;
                                    } elseif (!is_array($currentValue) && is_array($value)) {
                                        $currentValue = array_merge([$currentValue], $value);
                                    } else {
                                        $currentValue = [$currentValue, $value];
                                    }
                                    break;
                                // When duplicate detected concatenate colon and duplicate value
                                case self::MODE_QUERY_DUPLICATE_WITH_COLON & $mode:
                                    $currentValue .= ",{$value}";
                                    break;
                            }
                            $query->remove($parameter);
                            $query->add(new Parameter($name, $currentValue));
                        }
                    }
                } else {
                    $value = is_array($value) ? $this->decodeUrlArrayValue($value) : $this->decodeUrlValue($value);
                    $query->add(new Parameter($name, $value));
                }
            }
        }

        return $query;
    }

    /**
     * Decode urlencoded value
     * @param string $value
     * @return string
     */
    protected function decodeUrlValue(string $value) : string
    {
        return urldecode($value);
    }

    /**
     * Decode urlencoded array of values
     * @param array $value
     * @return array
     */
    protected function decodeUrlArrayValue(array $value) : array
    {
        array_walk_recursive($value, [$this, 'decodeUrlValue']);

        return $value;
    }

    /**
     * Sets parsing mode
     * @param int $mode
     */
    public function setMode(int $mode)
    {
        $this->mode = $mode;
    }
}
