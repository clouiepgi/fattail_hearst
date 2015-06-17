<?php
/**
 * CentralDesktop Edge Authentication
 * User: clouie
 * Date: 6/16/15
 * Time: 4:30 PM
 */

namespace CentralDesktop\FatTail\Services\Auth;

use JWT;

class EdgeAuth {

    protected $authURL;
    protected $issuer;
    protected $scp;
    protected $grantType;
    protected $clientId;
    protected $privateKey;
    protected $client;

    public
    function __construct(
        $authURL,
        $issuer,
        $scp,
        $grantType,
        $clientId,
        $privateKey,
        \GuzzleHttp\Client $client
    ) {
        $this->authURL = $authURL;
        $this->issuer = $issuer;
        $this->scp = $scp;
        $this->grantType = $grantType;
        $this->clientId = $clientId;
        $this->privateKey = $privateKey;
        $this->client = $client;
    }

    /**
     * Gets the access token for use with CD edge.
     */
    public
    function getAccessToken() {
        $user = [
            "iss" => $this->clientId,
            "aud" => $this->issuer,
            "exp" => time() + 600000,
            "iat" => time(),
            "scp" => $this->scp
        ];

        $authToken = JWT::encode($user, $this->privateKey, 'RS256');

        $accessToken = null;

        $formParams = [
            'grant_type' => $this->grantType,
            'assertion'  => $authToken
        ];

        try {
            $httpResponse = $this->client->post(
                $this->authURL,
                [
                    'form_params' => $formParams
                ]
            );
            $jsonResponse = json_decode($httpResponse->getBody());

            $accessToken = $jsonResponse->access_token;
        }
        catch (\GuzzleHttp\Exception\RequestException $e) {
            // TODO
            print_r($e->getMessage());
            if ($e->getResponse()) {
                 print_r($e->getResponse()->getBody());
            }
        }
        catch (\Exception $e) {
            // TODO
            print_r($e);
        }

        return $accessToken;
    }
}
