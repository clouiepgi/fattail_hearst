<?php

namespace  CentralDesktop\FatTail\Entities;

class Milestone extends Entity {

    public $c_drop_id  = null;
    public $name       = null;
    private $tasklists = [];

    function __construct($hash, $name, $c_drop_id) {
        parent::__construct($hash);
        $this->name = $name;
        $this->c_drop_id = $c_drop_id;
    }

    /**
     * Sets the milestone tasklists.
     *
     * @param $tasklists Array of tasklists with their name as the key.
     */
    public
    function set_tasklists($tasklists) {

        $this->tasklists = $tasklists;
    }

    /**
     * Adds a tasklist to milestone.
     *
     * @param $tasklist The tasklist to add to milestone.
     */
    public
    function add_tasklist($tasklist) {

        $this->tasklists[$tasklist->name] = $tasklist;
    }

    /**
     * Checks if a tasklist exists in milestone.
     *
     * @param $name The name of the tasklist
     * @return true if it does, false otherwise
     */
    public
    function has_tasklist($name) {

        return isset($this->tasklists[$name]);
    }
}
