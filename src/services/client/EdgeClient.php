<?php
/**
 * CentralDesktop Edge Client for calling Edge API endpoints.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 11:06AM
 */

namespace CentralDesktop\FatTail\Services\Client;

use CentralDesktop\FatTail\Services\Auth\EdgeAuth;

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
        //\GuzzleHttp\Client $http_client,
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
     * @param $path The path for the resource.
     * @param $query_params The query string parameters in array key value form.
     * @param $content_array The content to send in array format.
     *
     * @return guzzlehttp response
     */
    public
    function call($method, $path, $query_params = [], $content_array = []) {

        // Get URL and header information
        $url = $this->get_url($path, $query_params);
        $headers = $this->get_headers([
            'Content-Type' => 'application/json'
        ]);
        $content = json_encode($content_array);

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

        return $http_response;
    }

    /**
     * Builds the complete resource URL.
     *
     * @param $path The path to the resource.
     * @return The complete URL to the resource.
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
     * @return an array representing the header keys and values
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
