<?php

namespace  CentralDesktop\FatTail\Entities;

class Entity {

    public $hash = null;

    /**
     * Entity constructor.
     * @param $hash string
     */
    function __construct($hash) {
        $this->hash = $hash;
    }
}
