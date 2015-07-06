<?php

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Entities\Account;
use CentralDesktop\FatTail\Entities\Milestone;
use CentralDesktop\FatTail\Entities\Workspace;
use CentralDesktop\FatTail\Services\Client\EdgeClient;

use JmesPath;
use Psr\Log\LoggerAwareTrait;

class EdgeService {
    use LoggerAwareTrait;

    private $client = null;
    private $cache  = null;

    private $USER_TO_ROLE_PATH_TEMPLATE  = 'workspaces/%s/roles/%s/addUsers';

    public
    function __construct(EdgeClient $client, SyncCache $cache) {
        $this->client = $client;
        $this->cache  = $cache;
    }

    /**
     * Creates an account on CD.
     *
     * @param $name The account name.
     * @param $custom_fields An array of custom fields.
     *
     * @returns A new Account
     */
    public
    function create_cd_account($name, $custom_fields = []) {

        $details = new \stdClass();
        $details->accountName = $name;

        // Prepare data for request
        $path = 'accounts';
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        $http_response = $this->cd_post($path, $details);

        $account_hash = $http_response->getContent();

        $account = new Account(
            $account_hash,
            $custom_fields['c_client_id']
        );

        return $account;
    }

    /**
     * Creates a workspace on CD.
     *
     * @param $account_id The CD account id this workspace will be under.
     * @param $name The name of the workspace.
     * @param $template_hash The hash of the workspace template.
     * @param $custom_fields The custom fields of the workspace.
     *
     * @return A new Workspace
     */
    public
    function create_cd_workspace(
        $account_id,
        $name,
        $template_hash,
        $custom_fields = []
    ) {
        $details = new \stdClass();
        $details->workspaceName = $name;
        $details->workspaceType = $template_hash;

        // Prepare data for request
        $path = 'accounts/' . $account_id . '/workspaces';
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        $http_response = $this->cd_post($path, $details);

        $workspace_hash = $http_response->getContent();

        $workspace = new Workspace(
            $workspace_hash,
            $custom_fields['c_order_id']
        );

        return $workspace;
    }

    /**
     * Puts together data in the correct format for Edge API
     * milestone creations.
     *
     * @param $workspace_id The workspace id the milestone will be under.
     * @param $name The name of the milestone.
     * @param $description The description of the milestone.
     * @param $start_date The start date of the milestone.
     * @param $end_date The end date of the milestone.
     * @param $custom_fields An array of custom fields.
     *
     * @return A new Milestone.
     */
    public
    function create_cd_milestone(
        $workspace_id,
        $name,
        $description,
        $start_date,
        $end_date,
        $custom_fields = []
    ) {
        $details = new \stdClass();
        $details->title = $name;
        $details->startDate = $start_date;
        $details->dueDate = $end_date;
        $details->description = $description;
        $details->reminders = [''];
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        // Create a new milestone
        $path = 'workspaces/' . $workspace_id . '/milestones';

        $http_response = $this->cd_post($path, $details);

        $milestone_hash = $http_response->getContent();

        $milestone = new Milestone(
            $milestone_hash,
            $custom_fields['c_drop_id']
        );

        return $milestone;
    }

    /**
     * Puts together data in the correct format for Edge API
     * milestone updates.
     *
     * @param $milestone_id The milestone id.
     * @param $name The name of the milestone.
     * @param $description The description of the milestone.
     * @param $start_date The start date of the milestone.
     * @param $end_date The end date of the milestone.
     * @param $custom_fields An array of custom fields.
     *
     * @return True on success, false otherwise
     */
    public
    function update_cd_milestone(
        $milestone_id,
        $name,
        $description,
        $start_date,
        $end_date,
        $custom_fields
    ) {
        $details = new \stdClass();
        $details->title = $name;
        $details->start_date = $start_date;
        $details->end_date = $end_date;
        $details->description = $description;
        $details->reminders = [''];
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        // Create a new milestone
        $path = 'milestones/' . $milestone_id . '/updateDetail';

        $http_response = $this->cd_post($path, $details);

        return $http_response->isSuccessful();
    }

    /**
     * Gets all the cd accounts.
     *
     * @return An array of Accounts.
     */
    public
    function get_cd_accounts() {

        $accounts    = [];
        $last_record = '';
        $path        = 'accounts';

        do {

            $query_params = ['limit' => 100];

            if ($last_record !== '') {
                $query_params['lastRecord'] = $last_record;
            }

            $http_response = $this->cd_get($path, $query_params);

            $json = json_decode($http_response->getContent());
            if (property_exists($json, 'items')) {
                $data = $json->items;
            }
            else {
                break;
            }

            foreach ($data as $account_data) {

                $c_client_id = null;
                if (property_exists($account_data->details, 'customFields')) {

                    $c_client_id = JmesPath\Env::search(
                        "customFields[?fieldApiId=='c_client_id'].value | [0]",
                        $account_data->details
                    );
                }

                if (empty($c_client_id)) {
                    // Skip any accounts that aren't relevant
                    continue;
                }

                $accounts[$c_client_id] = new Account(
                    $account_data->id,
                    $c_client_id
                );
            }

            $last_record = $json->lastRecord;
        } while ($last_record !== '');

        return $accounts;
    }

    /**
     * Gets all the cd workspaces.
     *
     * @param $account_hash The account hash of the workspaces.
     * @return An array of workspaces belonging to account.
     */
    public
    function get_cd_workspaces($account_hash) {

        $workspaces  = [];
        $last_record = '';
        $path        = 'accounts/' . $account_hash . '/workspaces';

        do {

            $query_params = ['limit' => 100];

            if ($last_record !== '') {
                $query_params['lastRecord'] = $last_record;
            }

            $http_response = $this->cd_get($path, $query_params);

            $json = json_decode($http_response->getContent());
            if (property_exists($json, 'items')) {
                $data = $json->items;
            }
            else {
                break;
            }

            foreach ($data as $workspace_data) {

                // Skip deleted workspaces
                if (preg_match('/^deleted.*/', $workspace_data->details->urlShortName)) {
                    continue;
                }

                $c_order_id = null;
                if (property_exists($workspace_data->details, 'customFields')) {

                    $c_order_id = JmesPath\Env::search(
                        "customFields[?fieldApiId=='c_order_id'].value | [0]",
                        $workspace_data->details
                    );
                }

                if (empty($c_order_id)) {
                    // Skip any workspaces that aren't relevant
                    continue;
                }

                $workspaces[$c_order_id] = new Workspace(
                    $workspace_data->id,
                    $c_order_id
                );
            }

            $last_record = $json->lastRecord;
        } while ($last_record !== '');

        return $workspaces;
    }

    /**
     * Gets all the cd milestones.
     *
     * @param $workspace_hash The CD workspace hash
     * @return An array of Milestones belonging to workspace
     */
    public
    function get_cd_milestones($workspace_hash) {

        $milestones  = [];
        $last_record = '';
        $path        = 'workspaces/' . $workspace_hash . '/milestones';

        do {

            $query_params = ['limit' => 100];

            if ($last_record !== '') {
                $query_params['lastRecord'] = $last_record;
            }

            $http_response = $this->cd_get($path, $query_params);

            $json = json_decode($http_response->getContent());
            if (property_exists($json, 'items')) {
                $data = $json->items;
            }
            else {
                break;
            }

            foreach ($data as $milestone_data) {

                $c_drop_id = null;
                if (property_exists($milestone_data->details, 'customFields')) {

                    $c_drop_id = JmesPath\Env::search(
                        "customFields[?fieldApiId=='c_drop_id'].value | [0]",
                        $milestone_data->details
                    );
                }

                if (empty($c_drop_id)) {
                    // Skip any milestones that aren't relevant
                    continue;
                }

                $milestones[$c_drop_id] = new Milestone(
                    $milestone_data->id,
                    $c_drop_id
                );
            }

            // For some reason the lastRecord
            // field doesn't exists when lastRecord is empty
            // unlike the other endpoints
            if (property_exists($json, 'lastRecord')) {
                $last_record = $json->lastRecord;
            }
            else {
                break;
            }
        } while($last_record !== '');

        return $milestones;
    }

    /**
     * Assigns a user based on their full name to
     * a role by its name.
     *
     * @param $full_name The user's full name.
     * @param $role_hash The role's hash.
     * @param $workspace_hash The workspace's hash.
     */
    public
    function assign_user_to_role($full_name, $role_hash, $workspace_hash) {

        // Search for the user by name
        $user_hash = $this->get_cd_user_id_by_name($full_name);

        if ($user_hash == null) {
            $this->logger->warning('Failed to find the specified user. ' .
                'Continuing without assigning the user to the role.');
        }
        else if ($role_hash == null) {
            $this->logger->warning('Failed to find Central Desktop role.' .
                'Make sure the specified role exists. ' .
                'Continuing without assigning the user to the role.');
        }
        else {

            // Add user to 'Salesrep' workspace role
            $add_user_to_role_path = sprintf(
                $this->USER_TO_ROLE_PATH_TEMPLATE,
                $workspace_hash,
                $role_hash
            );
            $body = new \stdClass();
            $body->userIds = [
                $user_hash
            ];
            $body->clearExisting = false;
            $http_response = $this->cd_post(
                $add_user_to_role_path,
                $body
            );
        }
    }

    /**
     * Makes POST requests to Edge.
     *
     * @param $path The path for the resource
     * @param $details An array/object representing the entity data
     *
     * @return A Psr\Http\Message\ResponseInterface object
     */
    protected
    function cd_post($path, $details) {

        $http_response = null;
        try {
            $http_response = $this->client->call(
                EdgeClient::METHOD_POST,
                $path,
                [],
                $details
            );
        }
        catch (\Exception $e) {
            // Failed to create new entity
            $this->logger->error('Error talking with Edge API');
            $this->logger->error($e);
            $this->logger->error('Exiting.');
            exit;
        }

        return $http_response;
    }

    /**
     * Makes GET requests to Edge.
     *
     * @param $path The path for the resource
     * @param $query_params An array/object representing the entity data
     *
     * @return A Psr\Http\Message\ResponseInterface object
     */
    protected
    function cd_get($path, $query_params) {

        $http_response = null;
        try {
            $http_response = $this->client->call(
                EdgeClient::METHOD_GET,
                $path,
                $query_params
            );
        }
        catch (\Exception $e) {
            // Failed to create new entity
            $this->logger->error('Error talking with Edge API');
            $this->logger->error($e);
            $this->logger->error('Exiting.');
            exit;
        }

        return $http_response;
    }

    /**
     * Finds a Central Desktop user by first name and last name.
     * The names will be formatted to "first last" and compared.
     *
     * @param $first_name The first name of the user.
     *
     * @return The user id.
     */
    private
    function get_cd_user_id_by_name($full_name) {

        $users = $this->cache->get_users();

        if ($users === null) {
            // Cache hasn't been set yet

            $last_record = '';
            $path        = 'users';
            $users       = [];

            do {

                $query_params = ['limit' => 100];

                if ($last_record !== '') {
                    $query_params['lastRecord'] = $last_record;
                }

                $http_response = $this->cd_get($path, $query_params);

                $json = json_decode($http_response->getContent());
                if (property_exists($json, 'items')) {
                    foreach ($json->items as $user) {
                        $name         = strtolower($user->details->fullName);
                        $users[$name] = $user->id;
                    }
                }
                else {
                    break;
                }

                $last_record = $json->lastRecord;
            } while ($last_record !== '');

            $this->cache->set_users($users);
        }

        $full_name_lower = strtolower($full_name);

        if (array_key_exists($full_name_lower, $users)) {

            return $users[$full_name_lower];
        }

        return null;
    }

    /**
     * Builds the custom fields for use with
     * the Edge API call.
     *
     * @param $custom_fields An array of custom field and value pairs.
     *
     * @return An array of objects representing custom fields.
     */
    private
    function create_cd_custom_fields($custom_fields) {

        $fields = [];
        foreach ($custom_fields as $name => $value) {
            $fields[] = $this->create_cd_custom_field($name, $value);
        }

        return $fields;
    }

    /**
     * Creates a custom field for use with Edge.
     */
    private
    function create_cd_custom_field($name, $value) {

        $item = new \stdClass();
        $item->fieldApiId = $name;
        $item->value = $value;

        return $item;
    }

}