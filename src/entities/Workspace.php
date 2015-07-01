<?php

namespace  CentralDesktop\FatTail\Entities;

class Workspace extends CD_Entity {

    public $c_order_id = null;
    public $milestones = [];
    public $fattail_order = null;


    function __construct($hash, $c_order_id, $fattail_order = null) {
        parent::__construct($hash);
        $this->hash = $hash;
        $this->c_order_id = $c_order_id;
        $this->fattail_order = $fattail_order;
    }

    /**
     * Finds a milestone with c_drop_id.
     *
     * @param $c_drop_id The FatTail drop id
     * @return CD_Milestone with c_drop_id or null if not found
     */
    public
    function find_milestone_by_c_drop_id($c_drop_id) {

        foreach ($this->milestones as $milestone) {
            if ($milestone->c_drop_id == $c_drop_id) {
                return $milestone;
            }
        }

        return null;
    }
}
