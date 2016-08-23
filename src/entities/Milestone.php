<?php

namespace  CentralDesktop\FatTail\Entities;

class Milestone extends Entity {

    public $c_drop_id  = null;
    private $tasklists = [];

    function __construct($hash, $c_drop_id) {
        parent::__construct($hash);
        $this->c_drop_id = $c_drop_id;
    }

    /**
     * Sets the milestone tasklists.
     *
<<<<<<< HEAD
     * @param $tasklists Array of tasklists with their name as the key.
=======
     * @param $tasklists array of tasklists with their name as the key.
>>>>>>> local/script_improvements
     */
    public
    function set_tasklists($tasklists) {

        $this->tasklists = $tasklists;
    }

    /**
     * Adds a tasklist to milestone.
     *
<<<<<<< HEAD
     * @param $tasklist The tasklist to add to milestone.
=======
     * @param $tasklist Tasklist The tasklist to add to milestone.
>>>>>>> local/script_improvements
     */
    public
    function add_tasklist($tasklist) {

        $this->tasklists[$tasklist->name] = $tasklist;
    }

    /**
     * Checks if a tasklist exists in milestone.
     *
<<<<<<< HEAD
     * @param $name The name of the tasklist
=======
     * @param $name string The name of the tasklist
>>>>>>> local/script_improvements
     * @return true if it does, false otherwise
     */
    public
    function has_tasklist($name) {

        return isset($this->tasklists[$name]);
    }
}
