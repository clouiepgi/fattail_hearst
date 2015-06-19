<?php
/**
 * CentralDesktop Edge Authentication
 * User: clouie
 * Date: 6/16/15
 * Time: 4:30 PM
 */

namespace CentralDesktop\FatTail\Services\Auth;

use JWT;
use Psr\Log\LoggerAwareTrait;

class EdgeAuth {
    use LoggerAwareTrait;

    protected $auth_url;
    protected $issuer;
    protected $scp;
    protected $grant_type;
    protected $client_id;
    protected $private_key;
    protected $client;

    public
    function __construct(
        $auth_url,
        $issuer,
        $scp,
        $grant_type,
        $client_id,
        $private_key,
        \GuzzleHttp\Client $client
    ) {
        $this->auth_url = $auth_url;
        $this->issuer = $issuer;
        $this->scp = $scp;
        $this->grant_type = $grant_type;
        $this->client_id = $client_id;
        $this->private_key = $private_key;
        $this->client = $client;
    }

    /**
     * Gets the access token for use with CD edge.
     */
    public
    function getAccessToken() {
        $user = [
            "iss" => $this->client_id,
            "aud" => $this->issuer,
            "exp" => time() + 600000,
            "iat" => time(),
            "scp" => $this->scp
        ];

        $auth_token = JWT::encode($user, $this->private_key, 'RS256');

        $access_token = null;

        $form_params = [
            'grant_type' => $this->grant_type,
            'assertion'  => $auth_token
        ];

        try {
            $http_response = $this->client->post(
                $this->auth_url,
                [
                    'form_params' => $form_params
                ]
            );
            $json_response = json_decode($http_response->getBody());

            $access_token = $json_response->access_token;
        }
        catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->logger->error('Bad http request made.');
            if ($e->getResponse()) {
                $this->logger->error($e->getResponse());
            }
            $this->logger->error('Exiting.');
            exit;
        }
        catch (\Exception $e) {
            $this->logger->error('Bad http request made.');
            $this->logger->error($e);
            $this->logger->error('Exiting.');
            exit;
        }

        return $access_token;
    }
}
