<?php
/**
 * FatTail Client for calling SOAP endpoints.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 1:26 PM
 */

namespace CentralDesktop\FatTail\Services\Client;

use Psr\Log\LoggerAwareTrait;

class FatTailClient {
    use LoggerAwareTrait;

    protected $soap_client = null;

    public
    function __construct(
        \WsSoap\Client $soap_client,
        \SoapHeader $api_version_header
    ) {
        $this->soap_client = $soap_client;
        $this->soap_client->__setSoapHeaders($api_version_header);
    }

    public
    function call($name, $params = []) {

        $response = null;
        try {
            $response = $this->soap_client->$name($params);
        }
        catch (\Exception $e) {

            $this->logger->error(
                'Failed to make a SOAP call to FatTail. Exiting.'
            );
            print_r($this->soap_client->__getLastRequest());
            exit;
        }
        print_r($this->soap_client->__getLastRequest());

        return $response;
    }
}
