<?php

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Entities\Account;

class SyncCache {

    private $accounts           = null;
    private $users              = null;
    private $tasklist_templates = [];

    /**
     * Sets the accounts.
     *
     * @param $accounts An array of Accounts.
     */
    public
    function set_accounts($accounts) {

        $this->accounts = $accounts;
    }

    /**
     * Sets the users.
     *
     * @param $users An array of Users.
     */
    public
    function set_users($users) {

        $this->users = $users;
    }

    /**
     * Returns the array of users.
     *
     * @return An array of Users.
     */
    public
    function get_users() {

        return $this->users;
    }

    /**
     * Adds an Account.
     *
     * @param $account A Account.
     */
    public
    function add_account(Account $account) {

        $this->accounts[$account->c_client_id] = $account;
    }

    /**
     * Finds an Account by the FatTail client id.
     *
     * @param $c_client_id The FatTail client id.
     * @return The account with $c_client_id or null if not found.
     */
    public
    function find_account_by_c_client_id($c_client_id) {

        if (array_key_exists($c_client_id, $this->accounts)) {
            return $this->accounts[$c_client_id];
        }

        return null;
    }

    /**
     * Adds a tasklist template to the cache.
     *
     * @param $name The name of the tasklist template
     * @param $hash The tasklist template hash.
     */
    public
    function set_tasklist_templates($templates) {
        $this->tasklist_templates = $templates;
    }

    /**
     * Gets the hash of a tasklist template.
     *
     * @param $name The name of the tasklist template.
     * @return The tasklist template hash or null if not found.
     */
    public
    function get_tasklist_template($name) {

        if (isset($this->tasklist_templates[$name])) {
            return $this->tasklist_templates[$name];
        }

        return null;
    }
}