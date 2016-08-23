<?php
/**
 * iMeet® Central Edge Client for calling Edge API endpoints.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 11:06AM
 */

namespace CentralDesktop\FatTail\Services\Client;

use CentralDesktop\FatTail\Services\Auth\EdgeAuth;
use Exception;
use Psr\Log\LoggerAwareTrait;

class EdgeClient {
    use LoggerAwareTrait;

    protected $auth_client  = null;
    protected $http_client  = null;
    protected $access_token = null;
    protected $base_url     = null;
    const     METHOD_GET    = 'get';
    const     METHOD_POST   = 'post';
    const     METHOD_DELETE = 'delete';

    public
    function __construct(
        EdgeAuth $edge_auth,
        \Buzz\Browser $http_client,
        $base_url
    ) {
        $this->auth_client = $edge_auth;
        $this->http_client = $http_client;
        $this->base_url    = $base_url;
    }

    /**
     * Initializes the EdgeClient for use.
     */
    public
    function init() {
        if ($this->access_token === null) {
            $this->access_token = $this->auth_client->get_access_token();
        }
    }

    /**
     * Calls get requests to an Edge API endpoint.
     *
     * @param $path string The path for the resource.
     * @param $query_params array The query string parameters in array key value form.
     * @param $content_array array The content to send in array format.
     * @param $attempts integer The number of times to try an request before giving up
     *
     * @return object response
     */
    public
    function call($method, $path, $query_params = [], $content_array = [], $attempts = 3) {

        // Get URL and header information
        $url = $this->get_url($path, $query_params);
        $base_headers = [
            'Content-Type' => 'application/json'
        ];
        if (EdgeClient::METHOD_GET === $method) {
            $base_headers = [
                'Content-Type' => 'plain/text'
            ];
        }
        $headers = $this->get_headers($base_headers);
        $content = json_encode($content_array);

        try {
            // Make call based on method type
            $method = strtolower($method);
            switch ($method) {
                case EdgeClient::METHOD_POST:
                    $http_response = $this->http_client->post($url, $headers, $content);
                    break;
                case EdgeClient::METHOD_DELETE:
                    $http_response = $this->http_client->delete($url, $headers);
                    break;
                default:
                    $http_response = $this->http_client->get($url, $headers);
            }

            if (
                $http_response->getStatusCode() === 401 &&
                $http_response->getContent() == 'Access token has expired'
            ) {

                // If the access token has expired, get a new one and try again
                $this->logger->info('Access token has expired, attempting to get a new one.');
                $this->access_token = $this->auth_client->get_access_token();

                $http_response = $this->call($method, $path, $query_params, $content_array);
            }
        }
        catch (Exception $e) {
            $attempts--;
            if ($attempts > 0) {
                // If a request fails, lets try it again until we're out of attempts
                $this->call($method, $path, $query_params, $content_array, $attempts);
            }
            else {
                // If we're out of attempts throw the exception back up
                throw $e;
            }
        }

        return $http_response;
    }

    /**
     * Builds the complete resource URL.
     *
     * @param $path string The path to the resource.
     * @return string The complete URL to the resource.
     */
    private
    function get_url($path, $query_params = []) {

        $full_path = $this->base_url . $path;

        if (count($query_params) > 0) {

            $full_path .= '?' . http_build_query($query_params);
        }

        return $full_path;
    }

    /**
     * Builds the headers for API request.
     *
     * @param array array of headers
     * @return array representing the header keys and values
     */
    private
    function get_headers($other_headers) {

        if (!$this->access_token) {
             $this->init();
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token
        ];

        $headers = array_merge($headers, $other_headers);

        return $headers;
    }
}
