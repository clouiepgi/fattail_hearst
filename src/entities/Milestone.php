<?php

namespace  CentralDesktop\FatTail\Entities;

class Milestone extends CD_Entity {

    public $c_drop_id = null;
    public $fattail_drop = null;

    function __construct($hash, $c_drop_id, $fattail_drop = null) {
        parent::__construct($hash);
        $this->c_drop_id = $c_drop_id;
        $this->fattail_drop = $fattail_drop;
    }
}
