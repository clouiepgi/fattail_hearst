<?php

namespace  CentralDesktop\FatTail\Entities;

class CD_Entity {

    public $hash = null;

    function __construct($hash) {
        $this->hash = $hash;
    }
}
