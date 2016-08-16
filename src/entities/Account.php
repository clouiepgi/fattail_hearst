<?php

namespace  CentralDesktop\FatTail\Entities;

class Account extends Entity {

    public $c_client_id = null;

    /**
     * Account constructor.
     * @param $hash string
     * @param $c_client_id integer
     */
    function __construct($hash, $c_client_id) {
        parent::__construct($hash);
        $this->c_client_id = $c_client_id;
    }
}
