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

class EdgeClient {

    protected $authClient  = null;
    protected $httpClient  = null;
    protected $accessToken = null;
    protected $baseURL = null;
    const METHOD_GET = 'get';
    const METHOD_POST = 'post';
    const METHOD_DELETE = 'delete';

    public
    function __construct(
        EdgeAuth $edgeAuth,
        \GuzzleHttp\Client $httpClient,
        $baseURL
    ) {
        $this->authClient = $edgeAuth;
        $this->httpClient = $httpClient;
        $this->baseURL = $baseURL;
    }

    /**
     * Initializes the EdgeClient for use.
     */
    public
    function init() {
        if ($this->accessToken === null) {
            $this->accessToken = $this->authClient->getAccessToken();
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
    function call($method, $path, $queryParams = null, $formParams = null) {

        // Get URL and header information
        $url = $this->getURL($path);
        $reqData = $this->prepareRequestData($queryParams, $formParams);

        // Make call based on method type
        $method = strtolower($method);
        switch ($method) {
            case EdgeClient::METHOD_POST:
                $httpResponse = $this->httpClient->post($url, $reqData);
                break;
            case EdgeClient::METHOD_DELETE:
                $httpResponse = $this->httpClient->delete($url, $reqData);
                break;
            default:
                $httpResponse = $this->httpClient->get($url, $reqData);
        }

        return $httpResponse;
    }

    /**
     * Builds the complete resource URL.
     *
     * @param $path The path to the resource.
     * @return The complete URL to the resource.
     */
    private
    function getURL($path) {

        return $this->baseURL . $path;
    }

    /**
     * Builds the headers for API request.
     *
     * @return an array representing the header keys and values
     */
    private
    function getHeaders() {

        if (!$this->accessToken) {
             $this->init();
        }

        return [
             'Authorization' => 'Bearer ' . $this->accessToken
        ];
    }

    /**
     * Prepares the request data for a request.
     *
     * @param $queryParams The query string parameters for the request.
     * @param $jsonData The JSON data in PHP format for the request.
     *
     * @return An array representing the request data.
     */
    private
    function prepareRequestData($queryParams = null, $jsonData = null) {

        $headers = $this->getHeaders();

        $request = [
             'headers' => $headers
        ];

        // Set a default context Id based on initialization
        if ($queryParams !== null) {
            $request['query'] = $queryParams;
        }
        if ($jsonData !== null) {
            $request['json'] = $jsonData;
        }

        return $request;
    }
}
