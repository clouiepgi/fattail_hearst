<?php

namespace  CentralDesktop\FatTail\Entities;

class Workspace extends Entity {

    public $c_order_id = null;
    private $milestones = [];


    function __construct($hash, $c_order_id) {
        parent::__construct($hash);
        $this->hash = $hash;
        $this->c_order_id = $c_order_id;
    }

    /**
     * Sets the milestones.
     *
     * @param $milestones The milestones.
     */
    public
    function set_milestones($milestones) {
        $this->milestones = $milestones;
    }

    /**
     * Adds a milestone.
     *
     * @param Milestone $milestone
     */
    public
    function add_milestone(Milestone $milestone) {
        $this->milestones[$milestone->c_drop_id] = $milestone;
    }

    /**
     * Finds a milestone with c_drop_id.
     *
     * @param $c_drop_id The FatTail drop id
     * @return CD_Milestone with c_drop_id or null if not found
     */
    public
    function find_milestone_by_c_drop_id($c_drop_id) {

        if (array_key_exists($c_drop_id, $this->milestones)) {
            return $this->milestones[$c_drop_id];
        }

        return null;
    }
}
