<?php

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Entities\Account;
use CentralDesktop\FatTail\Entities\Milestone;
use CentralDesktop\FatTail\Entities\Tasklist;
use CentralDesktop\FatTail\Entities\TasklistTemplate;
use CentralDesktop\FatTail\Entities\Workspace;
use CentralDesktop\FatTail\Services\Client\EdgeClient;

use DateTime;
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
            $name,
            $custom_fields['c_order_id']
        );

        return $workspace;
    }

    /**
     * Updates a workspace on CD.
     *
     * @param $workspace_id
     * @param $name
     * @param array $custom_fields
     * @return mixed
     */
    public
    function update_cd_workspace(
        $workspace_id,
        $name,
        $custom_fields = []
    ) {
        $details = new \stdClass();
        $details->workspaceName = $name;
        $details->customFields = $this->create_cd_custom_fields($custom_fields);
        $path = 'workspaces/' . $workspace_id . '/updateDetails';

        $http_response = $this->cd_post($path, $details);

        return $http_response->isSuccessful();
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
            $name,
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
     * Gets a CD account with hash.
     *
     * @param $hash
     * @return Account|null
     */
    public
    function get_cd_account($hash) {

        $path = "accounts/$hash";

        $http_response = $this->cd_get($path, []);

        if ($http_response->getStatusCode() === 200) {
            $account_data = json_decode($http_response->getContent());
            if (property_exists($account_data->details, 'customFields')) {

                $c_client_id = JmesPath\Env::search(
                    "customFields[?fieldApiId=='c_client_id'].value | [0]",
                    $account_data->details
                );
            }

            return new Account($account_data->id, $c_client_id);
        }

        return null;
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
     * Gets a CD workspace with hash.
     *
     * @param $hash
     * @return Workspace|null
     */
    public
    function get_cd_workspace($hash) {

        $path = "workspaces/$hash";

        $http_response = $this->cd_get($path, []);

        if ($http_response->getStatusCode() === 200) {
            $workspace_data = json_decode($http_response->getContent());
            if (property_exists($workspace_data->details, 'customFields')) {

                $c_order_id = JmesPath\Env::search(
                    "customFields[?fieldApiId=='c_order_id'].value | [0]",
                    $workspace_data->details
                );
            }

            return new Workspace($workspace_data->id, $workspace_data->details->workspaceName, $c_order_id);
        }

        return null;
    }

    /**
     * Gets all the cd workspaces.
     *
     * @param $account_hash The account hash of the workspaces.
     * @return An array of workspaces belonging to account.
     */
    public
    function get_cd_workspaces($account_hash = null) {

        $workspaces  = [];
        $last_record = '';
        $path = 'workspaces';
        if ($account_hash) {
            $path = 'accounts/' . $account_hash . '/workspaces';
        }

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
                    $workspace_data->details->workspaceName,
                    $c_order_id
                );
            }

            $last_record = $json->lastRecord;
        } while ($last_record !== '');

        return $workspaces;
    }

    /**
     * Gets a CD milestone with hash.
     *
     * @param $hash
     * @return Milestone|null
     */
    public
    function get_cd_milestone($hash) {

        $path = "milestones/$hash";

        $http_response = $this->cd_get($path, []);

        if ($http_response->getStatusCode() === 200) {
            $milestone_data = json_decode($http_response->getContent());
            if (property_exists($milestone_data->details, 'customFields')) {

                $c_drop_id = JmesPath\Env::search(
                    "customFields[?fieldApiId=='c_drop_id'].value | [0]",
                    $milestone_data->details
                );
            }

            return new Milestone($milestone_data->id, $milestone_data->details->title, $c_drop_id);
        }

        return null;
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
            if ($json && property_exists($json, 'items')) {
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

                $milestone = new Milestone(
                    $milestone_data->id,
                    $milestone_data->details->title,
                    $c_drop_id
                );

                $milestones[$c_drop_id] = $milestone;

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
     * The gets an array of tasklists belonging to a milestone.
     *
     * @param $milestone The milestone of the tasklists being queried.
     * @return array An array of Tasklists
     */
    public
    function get_cd_tasklists($milestone) {

        $tasklists   = [];
        $last_record = '';
        $path        = 'milestones/' . $milestone->hash . '/tasklists';

        do {

            $query_params = ['limit' => 100];

            if (!empty($last_record)) {
                $query_params['lastRecord'] = $last_record;
            }

            $http_response = $this->cd_get($path, $query_params);

            $json = json_decode($http_response->getContent());

            if (!property_exists($json, 'items')) {

                // No more items to process so exit
                break;
            }

            foreach ($json->items as $tasklist_data) {

                $tasklist = new Tasklist(
                    $tasklist_data->id,
                    $tasklist_data->details->tasklistName
                );

                $tasklists[$tasklist->name] = $tasklist;
            }

            if (property_exists($json, 'lastRecord') && !empty($json->lastRecord)) {
                $last_record = $json->lastRecord;
            }
            else {
                $last_record = '';
            }
        } while (!empty($last_record));

        return $tasklists;
    }

    /**
     * Assigns a user based on their full name to
     * a role by its name.
     *
     * @param $full_name The user's full name.
     * @param $role_hash The role's hash.
     * @param $workspace_hash The workspace's hash.
     * @param $role_name THe role's name.
     */
    public
    function assign_user_to_role($full_name, $role_hash, $workspace_hash, $role_name) {

        // Search for the user by name
        $user_hash = $this->get_cd_user_id_by_name($full_name);

        if ($user_hash == null) {
            $this->logger->warning('Failed to find user ' . $full_name . ' in Central Desktop. ' .
                'Continuing without assigning the user ' . $full_name . ' to the ' . $role_name . ' role.');
        }
        else if ($role_hash == null) {
            $this->logger->warning('Failed to find role ' . $role_name . ' in Central Desktop. ' .
                'Make sure the ' . $role_name . ' role exists in Central Desktop. ' .
                'Continuing without assigning the user ' . $full_name . 'to the ' . $role_name . ' role.');
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
     * Adds tasklists to a milestone using an array of tasklist hashes.
     *
     * @param $milestone_hash The milestone to add tasklists to.
     * @param $tasklist_template_hashes An array of tasklist template hashes to use.
     * @param $start_date The start date for the tasklist
     */
    public
    function add_tasklists_to_milestone($milestone, $tasklist_template_names, $start_date) {

        $post_path = 'milestones/' . $milestone->hash . '/tasklists';
        foreach ($tasklist_template_names as $tasklist_template_name) {

            // Tasklist templates have names like
            // "DropTypes_TasklistName" so take out the "DropTypes_"
            $tasklist_name = $tasklist_template_name;
            if (($name = strrchr($tasklist_template_name, '_')) !== false) {
                $tasklist_name = substr($name, 1);
            }

            if (!$milestone->has_tasklist($tasklist_name)) {

                // Get the name of the tasklist template to reuse
                // as name of the template
                $template = $this->cache->get_tasklist_template($tasklist_template_name);

                // Client wants tasklist start dates to be -30 days of drop start date
                $start_date = new DateTime($start_date);

                $start_date->modify('-30 days');

                if ($template !== null) {

                    $body = new \stdClass();
                    $body->tasklistName     = $tasklist_name;
                    $body->startDate        = $start_date->format('Y-m-d');
                    $body->tasklistTemplate = $template->hash;

                    $tasklist_hash = $this->cd_post(
                        $post_path,
                        $body
                    );

                    $milestone->add_tasklist(
                        new Tasklist($tasklist_hash, $tasklist_template_name)
                    );
                }
                else {
                    $this->logger->warn(
                        'Failed to find tasklist template',
                        [
                            'Name'           => $tasklist_template_name,
                            'Milestone Hash' => $milestone->hash
                        ]
                    );
                }
            }
        }
    }

    /**
     * Gets all CD tasklist templates for lookup of hashes.
     *
     * @return array A hash of tasklist templates hashed by their name.
     */
    public
    function get_cd_tasklist_templates() {

        $templates   = [];
        $last_record = '';
        $path        = 'tasklists';

        do {

            $query_params = ['templateOnly' => true, 'limit' => 100];

            if (!empty($last_record)) {

                // Add last record for pagination
                $query_params['lastRecord'] = $last_record;
            }

            $http_response = $this->cd_get($path, $query_params);
            $json = json_decode($http_response->getContent());

            if (!property_exists($json, 'items')) {

                // No more items to process
                break;
            }

            foreach ($json->items as $template_data) {

                // Hash them by their name for easy lookup
                $tasklist_template = new TasklistTemplate($template_data->id);
                $templates[$template_data->details->tasklistName] = $tasklist_template;
            }

            if (property_exists($json, 'lastRecord') && !empty($json->lastRecord)) {
                $last_record = $json->lastRecord;
            }
            else {
                $last_record = '';
            }
        } while (!empty($last_record));

        return $templates;
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