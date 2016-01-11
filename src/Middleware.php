<?php
namespace RateLimiter;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Predis\Client as PredisClient;
use RateLimiter\Exceptions\RateLimitException;

/**
 * Class Middleware
 *
 * @package RateLimiter
 * @property-read array settings
 *
 */
class Middleware
{
    /**
     * Default settings
     *
     * @var array
     */
    protected $default_settings = [
        'hourly_request_limit' => 6000,
        'minute_request_limit' => 200,
        'second_request_limit' => 20,
    ];

    /**
     * @var string request tag
     */
    protected $request_tag = "";

    /**
     * @var bool should response contain limit headers
     */
    protected $rate_limit_headers = false;

    /**
     * @var int seconds for rate limit the action
     */
    protected $interval_seconds = 0;

    /**
     * @var ServerRequestInterface $request PSR-7 Request
     */
    protected $request = null;

    /**
     * @var ResponseInterface $response PSR-7 Request

     */
    protected $response = null;

    /**
     * @var array redis server options
     */
    protected $redis_options = [];

    /**
     * @var string redis prefix
     */
    protected $prefix = "rate_limits:";

    /**
     * @var PredisClient predis client
     */
    protected $predis_client = null;

    /**
     * List of proxy headers inspected for the client IP address
     *
     * @var array
     */
    protected $headersToInspect = [
        'X-Forwarded-For'
    ];

    /**
     * Middleware constructor.
     *
     * @param array $options
     * @param array $redis_options
     */
    public function __construct($options = [], $redis_options = [])
    {
        $default_settings = $this->default_settings;

        $this->settings = array_merge($default_settings, $options);

        $this->redis_options = $redis_options;
    }

    /**
     * @return string request tag
     */
    public function getRequestTag()
    {
        return $this->request_tag;
    }

    /**
     * @return PredisClient
     */
    protected function getRedisClient()
    {

        if ($this->predis_client == null) {
            $this->predis_client = new PredisClient($this->redis_options, ['prefix' => $this->prefix]);
        }

        return $this->predis_client;
    }

    /**
     * Rate limit middleware
     *
     * @param ServerRequestInterface $request PSR7 request
     * @param ResponseInterface $response PSR7 response
     * @param callable $next Next middleware
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, $next)
    {
        if ($this->settings['hourly_request_limit'] > 0) {
            $this->withRequest($request)->withClientIpAddress()->withName('requests:hourly')->withTimeInternal(3600)->limit($this->settings['hourly_request_limit']);
        }

        if ($this->settings['minute_request_limit'] > 0) {
            $this->withRequest($request)->withClientIpAddress()->withName('requests:minute')->withTimeInternal(60)->limit($this->settings['minute_request_limit']);
        }

        if ($this->settings['second_request_limit'] > 0) {
            $this->withRequest($request)->withClientIpAddress()->withName('requests:second')->withTimeInternal(1)->limit($this->settings['second_request_limit']);
        }

        $response = $next($request, $response);

        return $response;
    }

    /**
     * Set the headers to the response
     *
     * @param ResponseInterface $response
     * @param int $total_limit
     * @param int $remaining_limit
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function setHeaders(ResponseInterface $response, $total_limit, $remaining_limit)
    {
        $response = $response->withHeader('X-Rate-Limit-Limit', (string)$total_limit);
        $response = $response->withHeader('X-Rate-Limit-Remaining', (string)$remaining_limit);

        return $response;
    }

    /**
     * Limit by action name
     *
     * @param string $name unique name of action to rate limit
     *
     * @return Middleware
     */
    public function withName($name)
    {
        $this->request_tag .= $name . ':';

        return $this;
    }

    /**
     * Set a PSR-7 Request
     *
     * @param ServerRequestInterface $request PSR-7 Request
     *
     * @return Middleware
     */
    public function withRequest($request)
    {
        $this->request = $request;

        return $this;
    }

    /**
     * set a PSR-7 response
     *
     * @param ResponseInterface $response PSR7 response
     *
     * @return Middleware
     */
    public function withResponse($response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Set rate limit headers
     *
     * @param boolean $set_headers should rate limit headers be appended to response
     *
     * @return Middleware
     */
    public function withRateLimitHeaders($set_headers = true)
    {
        $this->rate_limit_headers = $set_headers;

        return $this;
    }

    /**
     * Limit by ip address
     *
     * @return Middleware
     */
    public function withClientIpAddress()
    {
        $this->request_tag .= $this->determineClientIpAddress($this->request) . ':';

        return $this;
    }

    /**
     * limit by user id
     *
     * @param string $user_id
     *
     * @return Middleware
     */
    public function withUserId($user_id)
    {
        $this->request_tag .= $user_id . ':';

        return $this;
    }

    /**
     * @param int $interval time internal, in seconds
     *
     * @return Middleware
     */
    public function withTimeInternal($interval = 3600)
    {
        $this->interval_seconds = $interval;

        return $this;
    }

    /**
     * Resets the request limit options
     */
    public function resetRequest()
    {
        $this->request_tag = '';
        $this->request = null;
        $this->response = null;
        $this->interval_seconds = 0;
        $this->rate_limit_headers = false;
    }

    /**
     * Record the action count to storage
     *
     * @param int $amount action amount
     *
     * @return int
     */
    public function record($amount = 1)
    {
        $actions_count = 0;

        try {
            $actions_count = $this->getRedisClient()->incrby($this->request_tag, $amount);
        } catch (\Exception $e) {
            //
        }

        // Must be their first visit so let's set the expiration time.
        if ($actions_count == $amount) {
            $this->getRedisClient()->expireat($this->request_tag, time() + $this->interval_seconds);
        }

        $this->resetRequest();

        return $actions_count;
    }

    /**
     * Rate limit a specific action
     *
     * @param int $limit hourly limit
     * @param int $amount amount of actions executed
     *
     * @throws RateLimitException
     *
     * @return ResponseInterface|null
     */
    public function limit($limit = 5000, $amount = 1)
    {
        $response = $this->response;
        $rate_limit_headers = $this->rate_limit_headers;

        $actions_count = $this->record($amount);

        if ($rate_limit_headers) {
            $response = $this->setHeaders($response, $limit, $limit - $actions_count);
        }

        if ($actions_count > $limit) {
            throw new RateLimitException('Rate limit exceeded.');
        }

        return $response;
    }

    /**
     * Reset all limits. useful for debugging
     * not recommended for production use
     */
    public function flushAll()
    {
        $this->_flushByLookup("*");
    }

    /**
     * Flush redis by a specific lookup
     *
     * @param string $lookup lookup key
     *
     * @return null
     */
    private function _flushByLookup($lookup)
    {
        $keys = $this->getRedisClient()->keys($lookup);

        $keys_with_no_prefix = [];
        foreach ($keys as $key) {
            $key = substr($key, strlen($this->prefix));
            $keys_with_no_prefix[] = $key;
        }

        if (count($keys_with_no_prefix) > 0) {
            $this->getRedisClient()->del($keys_with_no_prefix);
        }
    }

    /**
     * Find out the client's IP address from the headers available to us
     *
     * @param ServerRequestInterface $request PSR-7 Request
     *
     * @return string|null
     */
    protected function determineClientIpAddress($request)
    {
        $ipAddress = null;

        $serverParams = $request->getServerParams();
        if (isset($serverParams['REMOTE_ADDR']) &&
            $this->isValidIpAddress($serverParams['REMOTE_ADDR'])
        ) {
            $ipAddress = $serverParams['REMOTE_ADDR'];
        }

        foreach ($this->headersToInspect as $header) {
            if ($request->hasHeader($header)) {
                $ip = trim(current(explode(',', $request->getHeaderLine($header))));
                if ($this->isValidIpAddress($ip)) {
                    $ipAddress = $ip;
                    break;
                }
            }
        }


        return $ipAddress;
    }

    /**
     * Check that a given string is a valid IP address
     *
     * @param string $ip ip address
     *
     * @return boolean
     */
    protected function isValidIpAddress($ip)
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }
        return true;
    }
}