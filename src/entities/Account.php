<?php

namespace  CentralDesktop\FatTail\Entities;

class Account extends Entity {

    public $c_client_id = null;
    public $workspaces = [];
    public $fattail_client = null;

    function __construct($hash, $c_client_id, $fattail_client = null) {
        parent::__construct($hash);
        $this->c_client_id = $c_client_id;
        $this->fattail_client = $fattail_client;
    }

    /**
     * Finds a workspace with c_order_id.
     *
     * @param $c_order_id The FatTail order id
     * @return CD_Workspace with c_order_id or null if not found
     */
    public
    function find_workspace_by_c_order_id($c_order_id) {

        foreach ($this->workspaces as $workspace) {
            if ($workspace->c_order_id == $c_order_id) {
                return $workspace;
            }
        }

        return null;
    }
}
