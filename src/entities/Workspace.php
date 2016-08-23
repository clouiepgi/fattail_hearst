<?php

namespace  CentralDesktop\FatTail\Entities;

class Workspace extends Entity {

    public $c_order_id = null;

    /**
     * Workspace constructor.
     * @param $hash string
     * @param $c_order_id integer
     */
    function __construct($hash, $c_order_id) {
        parent::__construct($hash);
        $this->hash = $hash;
        $this->c_order_id = $c_order_id;
    }
}
