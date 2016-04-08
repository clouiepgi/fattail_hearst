<?php

namespace  CentralDesktop\FatTail\Entities;

class Tasklist extends Entity {

    public $name;

    public
    function __construct($hash, $name) {

        parent::__construct($hash);
        $this->name = $name;
    }
}
