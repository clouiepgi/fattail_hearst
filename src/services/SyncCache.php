<?php

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Entities\Account;

class SyncCache {

    private $accounts = null;
    private $users = null;

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
}