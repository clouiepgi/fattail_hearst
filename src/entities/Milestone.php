<?php

namespace  CentralDesktop\FatTail\Entities;

class Milestone extends Entity {

    public $c_drop_id = null;

    function __construct($hash, $c_drop_id) {
        parent::__construct($hash);
        $this->c_drop_id = $c_drop_id;
    }
}
