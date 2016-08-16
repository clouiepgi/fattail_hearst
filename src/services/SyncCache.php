<?php

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Entities\Account;
use CentralDesktop\FatTail\Entities\TasklistTemplate;
use CentralDesktop\FatTail\Entities\Workspace;
use PhpCollection\Sequence;
use PhpOption\None;
use PhpOption\Option;

class SyncCache {

    private $accounts           = [];
    private $workspaces         = [];
    private $users              = [];
    private $clients            = [];
    private $orders             = [];
    private $tasklist_templates = [];

    /**
     * Sets the FatTail clients and hashes them by client id
     * @param array $clients
     */
    public
    function set_clients(array $clients = []) {
        $this->clients = (new Sequence($clients))
            ->foldLeft([], function($clients, $client) {
                $clients[$client->ClientID] = $client;
                return $clients;
            });
    }

    /**
     * Gets a client by id.
     * @param $id integer
     * @return Option The client with $id
     */
    public
    function get_client($id) {
        if (!$this->clients || count($this->clients)) {
            return None::create();
        }

        return Option::fromValue($this->clients[$id]);
    }

    /**
     * Adds an order to the cache.
     * @param $order
     */
    public
    function add_order($order) {
        $this->orders[$order->OrderID] = $order;
    }

    /**
     * Gets an order from cache.
     * @param $id
     * @return Option
     */
    public
    function get_order($id) {
        return Option::fromValue($this->orders[$id]);
    }

    /**
     * Sets the accounts and hashes them by c_client_id.
     *
     * @param $accounts array An array of Accounts.
     */
    public
    function set_accounts(array $accounts = []) {
        $this->accounts = (new Sequence($accounts))
            ->foldLeft([], function(array $accounts, Account $account) {
                $accounts[$account->c_client_id] = $account;
                return $accounts;
            });
    }

    /**
     * Sets the users.
     *
     * @param $users array An array of Users.
     */
    public
    function set_users($users) {

        $this->users = $users;
    }

    /**
     * Returns the array of users.
     *
     * @return array An array of Users.
     */
    public
    function get_users() {

        return $this->users;
    }

    /**
     * Adds an Account.
     *
     * @param $account Account A Account.
     */
    public
    function add_account(Account $account) {
        $this->accounts[$account->c_client_id] = $account;
    }

    /**
     * Finds an Account by the FatTail client id.
     *
     * @param $c_client_id integer The FatTail client id.
     * @return Option The account with $c_client_id or null if not found.
     */
    public
    function find_account_by_c_client_id($c_client_id) {

        if (array_key_exists($c_client_id, $this->accounts)) {
            return Option::fromValue($this->accounts[$c_client_id]);
        }

        return None::create();
    }

    /**
     * Sets the tasklist templates
     *
     * @param $templates array
     */
    public
    function set_tasklist_templates(array $templates) {
        $this->tasklist_templates = $templates;
    }

    /**
     * Gets the hash of a tasklist template.
     *
     * @param $name string The name of the tasklist template.
     * @return TasklistTemplate The tasklist template hash or null if not found.
     */
    public
    function get_tasklist_template($name) {

        if (isset($this->tasklist_templates[$name])) {
            return $this->tasklist_templates[$name];
        }

        return null;
    }

    /**
     * Adds a workspace to the cache.
     * @param Workspace $workspace
     */
    public
    function add_workspace(Workspace $workspace) {
        $this->workspaces[$workspace->c_order_id] = $workspace;
    }

    /**
     * Sets the cache workspaces and hashes them by name.
     *
     * @param $workspaces array Workspaces hashed by their hash
     */
    public
    function set_workspaces($workspaces) {
        $this->workspaces = (new Sequence($workspaces))
            ->foldLeft([], function(array $workspaces, Workspace $workspace) {
                $workspaces[$workspace->c_order_id] = $workspace;
                return $workspaces;
            });
    }

    /**
     * Finds a workspace by its order id.
     *
     * @param $order_id integer the order id
     * @return Option
     */
    public
    function get_workspace_by_order_id($order_id) {
        return Option::fromValue($this->workspaces[$order_id]);
    }
}