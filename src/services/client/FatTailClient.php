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
    function __construct(\WsSoap\Client $soap_client) {
        $this->soap_client = $soap_client;
    }

    public
    function call($name, $params = null) {
        return $this->soap_client->$name($params);
    }
}
