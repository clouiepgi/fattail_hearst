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
        \GuzzleHttp\Client $http_client,
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
     * @param $params The query string parameters in array key value form.
     *
     * @return array representation of JSON response
     */
    public
    function call($method, $path, $query_params = null, $formParams = null) {

        // Get URL and header information
        $url = $this->get_url($path);
        $reqData = $this->prepare_request_data($query_params, $formParams);

        // Make call based on method type
        $method = strtolower($method);
        switch ($method) {
            case EdgeClient::METHOD_POST:
                $http_response = $this->http_client->post($url, $reqData);
                break;
            case EdgeClient::METHOD_DELETE:
                $http_response = $this->http_client->delete($url, $reqData);
                break;
            default:
                $http_response = $this->http_client->get($url, $reqData);
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
    function get_url($path) {

        return $this->base_url . $path;
    }

    /**
     * Builds the headers for API request.
     *
     * @return an array representing the header keys and values
     */
    private
    function get_headers() {

        if (!$this->access_token) {
             $this->init();
        }

        return [
             'Authorization' => 'Bearer ' . $this->access_token
        ];
    }

    /**
     * Prepares the request data for a request.
     *
     * @param $query_params The query string parameters for the request.
     * @param $json_date The JSON data in PHP format for the request.
     *
     * @return An array representing the request data.
     */
    private
    function prepare_request_data($query_params = null, $json_date = null) {

        $headers = $this->get_headers();

        $request = [
             'headers' => $headers,
             //'debug' => true
             'stream' => false
        ];

        // Set a default context Id based on initialization
        if ($query_params !== null) {
            $request['query'] = $query_params;
        }
        if ($json_date !== null) {
            $request['json'] = $json_date;
        }

        return $request;
    }
}
