<?php

namespace  CentralDesktop\FatTail\Entities;

class Entity {

    public $hash = null;

    function __construct($hash) {
        $this->hash = $hash;
    }
}
