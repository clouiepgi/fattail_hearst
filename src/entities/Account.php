<?php

namespace  CentralDesktop\FatTail\Entities;

use PhpOption\None;
use PhpOption\Option;

class Account extends Entity {

    public $c_client_id = null;
    private $workspaces = [];

    function __construct($hash, $c_client_id) {
        parent::__construct($hash);
        $this->c_client_id = $c_client_id;
    }

    /**
     * Sets the workspaces.
     *
     * @param $workspaces The workspaces.
     */
    public
    function set_workspaces($workspaces) {
        $this->workspaces = $workspaces;
    }

    /**
     * Adds a workspaces.
     *
     * @param Workspace $workspace
     */
    public
    function add_workspace(Workspace $workspace) {
        $this->workspaces[$workspace->c_order_id] = $workspace;
    }

    /**
     * Finds a workspace with c_order_id.
     *
     * @param $c_order_id integer FatTail order id
     * @return Option workspace with c_order_id or null if not found
     */
    public
    function find_workspace_by_c_order_id($c_order_id) {

        if (array_key_exists($c_order_id, $this->workspaces)) {
            return Option::fromValue($this->workspaces[$c_order_id]);
        }

        return None::create();
    }
}
