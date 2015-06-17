<?php
/**
 * FatTail Client for calling SOAP endpoints.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 1:26 PM
 */

namespace CentralDesktop\FatTail\Services\Client;

class FatTailClient {

    protected $soapClient = null;

    public
    function __construct(\WsSoap\Client $soapClient) {
        $this->soapClient = $soapClient;
    }

    public
    function call($name, $params = null) {
        return $this->soapClient->$name($params);
    }
}
