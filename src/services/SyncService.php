<?php
/**
 * Syncronization service between Edge and FatTail.
 *
 * User: clouie
 * Date: 6/17/15
 * Time: 3:50 PM
 */

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Services\Client\EdgeClient;
use CentralDesktop\FatTail\Services\Client\FatTailClient;
use CentralDesktop\FatTail\Entities\Account;
use CentralDesktop\FatTail\Entities\Milestone;
use CentralDesktop\FatTail\Entities\Workspace;

use League\Csv\Reader;
use Psr\Log\LoggerAwareTrait;

class SyncService {
    use LoggerAwareTrait;

    protected $edge_client    = null;
    protected $fattail_client = null;
    private $cd_accounts = [];

    private $PING_INTERVAL    = 5; // In seconds
    private $DONE             = 'done';
    private $tmp_dir          = 'tmp/';
    private $workspace_template_hash = 'pm';
    private $sales_role_hash = '';


    private $WORKSPACE_DYNAMIC_PROP_NAME = 'H_CD_Workspace_ID';
    private $MILESTONE_DYNAMIC_PROP_NAME = 'H_CD_Milestone_ID';
    private $USER_TO_ROLE_PATH_TEMPLATE = 'workspaces/%s/roles/%s/addUsers';

    public
    function __construct(
        EdgeClient $edge_client,
        FatTailClient $fattail_client,
        $tmp_dir = '',
        $workspace_template_hash = 'pm',
        $sales_role_hash = ''
    ) {
        $this->edge_client             = $edge_client;
        $this->fattail_client          = $fattail_client;
        $this->tmp_dir                 = $tmp_dir;
        $this->workspace_template_hash = $workspace_template_hash;
        $this->sales_role_hash         = $sales_role_hash;
    }

    /**
     * Syncs Edge and FatTail.
     */
    public
    function sync($report_name = '') {


        $this->logger->info("Starting report sync.\n");

        // Get all saved reports
        $report_list_result = $this
                              ->fattail_client
                              ->call('GetSavedReportList');
        $report_list        = $report_list_result
                              ->GetSavedReportListResult
                              ->SavedReport;

        // Find the specific report
        $report = null;
        foreach ($report_list as $report_item) {
            if ($report_item->Name === $report_name) {
                $report = $report_item;
            }
        }

        if ($report === null) {
            $this->logger->error("Unable to find requested report. Exiting.\n");
            exit;
        }

        $csv_path = $this->download_report_csv(
            $report,
            $this->tmp_dir
        );
        $reader = Reader::createFromPath($csv_path);
        $rows = $reader->fetchAll();

//        $order_workspace_property_id =
//            $this->get_fattail_order_dynamic_property_id(
//                $this->WORKSPACE_DYNAMIC_PROP_NAME
//            );
//
//        $drop_milestone_property_id =
//            $this->get_fattail_drop_dynamic_property_id(
//                $this->MILESTONE_DYNAMIC_PROP_NAME
//            );

        // Create mapping of column name with column index
        // Not necessary, but makes it easier to work with the data.
        // Can remove if optimization issues arise because of this
        $col_map = [];
        foreach ($rows[0] as $index => $name) {
            $col_map[$name] = $index;
        }

        $this->logger->info("Preparing to sync data. Please wait.\n");

        // Populate a local copy of CD accounts, workspace, and milestones
        $this->cd_accounts = array_map(function ($account) {

            $account->workspaces = array_map(function ($workspace) {

                $workspace->milestones = $this->get_cd_milestones($workspace->hash);

                return $workspace;
            }, $this->get_cd_workspaces($account->hash));

            return $account;
        }, $this->get_cd_accounts());

        // Iterate over CSV and process data
        // Skip the first and last rows since they
        // don't have the data we need
        for ($i = 1, $len = count($rows) - 1; $i < $len; $i++) {
            $this->logger->info("Processing next item. Please wait.");

            $row = $rows[$i];

            // Get client details
            $client_id = $row[$col_map['Client ID']];

            $client = $this->fattail_client->call(
                'GetClient',
                ['clientId' => $client_id]
            )->GetClientResult;

            // Get order details
            $order_id = $rows[$i][$col_map['Campaign ID']];
            $order = $this->fattail_client->call(
                'GetOrder',
                ['orderId' => $order_id]
            )->GetOrderResult;

            // Get drop details
            $drop_id = $rows[$i][$col_map['Drop ID']];
            $drop = $this->fattail_client->call(
                'GetDrop',
                ['dropId' => $drop_id]
            )->GetDropResult;

            // Process the client
            $cd_account = $this->find_account_by_c_client_id($client->ClientID);

            if ($cd_account === null) {
                // Create a new CD Account
                $custom_fields = [
                    'c_client_id' => $client->ClientID
                ];
                $cd_account = $this->create_cd_account(
                    $client->Name,
                    $custom_fields
                );

                $this->cd_accounts[$cd_account->hash] = $cd_account;
            }

            if ($client->ExternalID === '') {
                // Update the FatTail client external id
                // if it doesn't have a value
                $client->ExternalID = $cd_account->hash;

                // Update the FatTail Client with the
                // CD Account hash
                $this->fattail_client->call(
                    'UpdateClient',
                    ['client' => $client]
                );
            }

            // Process the order
            $cd_workspace = $cd_account->find_workspace_by_c_order_id($order->OrderID);
            if ($cd_workspace === null) {

                $custom_fields = [
                    'c_order_id'            => $order->OrderID,
                    'c_campaign_status'     => $row[$col_map['IO Status']],
                    'c_campaign_start_date' => $row[$col_map['Campaign Start Date']],
                    'c_campaign_end_date'   => $row[$col_map['Campaign End Date']]
                ];
                $cd_workspace = $this->create_cd_workspace(
                    $cd_account->hash,
                    $row[$col_map['Campaign Name']],
                    $this->workspace_template_hash,
                    $custom_fields
                );

                $cd_account->workspaces[$cd_workspace->hash] = $cd_workspace;
            }

            // TODO Update dynamic field

            // TODO uncomment when live
//            $this->fattail_client->call(
//                'UpdateOrder',
//                ['order' => $order]
//            );

            // Assign Salesrole
            // Splitting name, assuming only first and last name in
            // 'Last,' First format
            $sales_rep_name = $row[$col_map['Sales Rep']];
            $name_parts = explode(', ', $sales_rep_name);
            $full_name = strtolower($name_parts[1] . ' ' . $name_parts[0]);

            $this->assign_user_to_role(
                $full_name,
                $this->sales_role_hash,
                $cd_workspace->hash
            );

            // Process the drop
            $cd_milestone = $cd_workspace->find_milestone_by_c_drop_id($drop->DropID);
            $custom_fields = [
                'c_drop_id'              => $drop->DropID,
                'c_custom_unit_features' => $row[$col_map['(Drop) Custom Unit Features']],
                'c_kpi'                  => $row[$col_map['(Drop) Line Item KPI']],
                'c_drop_cost_new'        => $row[$col_map['Sold Amount']]
            ];
            if ($cd_milestone === null) {

                $cd_milestone = $this->create_cd_milestone(
                    $cd_workspace->hash,
                    $row[$col_map['Position Path']],
                    $row[$col_map['Drop Description']],
                    $row[$col_map['Start Date']],
                    $row[$col_map['End Date']],
                    $custom_fields
                );

                $cd_workspace->milestones[$cd_milestone->hash] = $cd_milestone;
            }
            else {

                $status = $this->update_cd_milestone(
                    $cd_milestone->hash,
                    $row[$col_map['Position Path']],
                    $row[$col_map['Drop Description']],
                    $row[$col_map['Start Date']],
                    $row[$col_map['End Date']],
                    $custom_fields
                );

                if (!$status) {
                    $this->logger->warning(
                        "Failed to updated a milestone. Continuing."
                    );
                }
            }

            // TODO Update dynamic field

            // TODO uncomment when live
//            $this->fattail_client->call(
//                'UpdateDrop',
//                ['drop' => $drop]
//            );
        }

        $this->logger->info("Finished report sync.\n");
        $this->logger->info("Cleaning up temporary CSV report.\n");
        $this->clean_up($this->tmp_dir);
        $this->logger->info("Finished cleaning up CSV report.\n");
        $this->logger->info("Done.\n");
    }

    /**
     * Downloads a CSV report from FatTail.
     *
     * @param $report The report that will
     *        have it's CSV file generated.
     * @param $dir The system directory to save the CSV to.
     *
     * @return System path to the CSV file.
     */
    protected
    function download_report_csv($report, $dir = '') {
        $this->logger->info('Downloading CSV report.');

        // Get individual report details
        $saved_report_query = $this->fattail_client->call(
            'GetSavedReportQuery',
            [ 'savedReportId' => $report->SavedReportID ]
        )->GetSavedReportQueryResult;

        // Get the report parameters
        $report_query = $saved_report_query->ReportQuery;
        $report_query = ['ReportQuery' => $report_query];
        // Convert entirely to use only arrays, no objects
        $report_query_formatted = $this->convert_to_arrays($report_query);

        // Run reports jobs
        $run_report_job = $this->fattail_client->call(
            'RunReportJob',
            ['reportJob' => $report_query_formatted]
        )->RunReportJobResult;
        $report_job_id = $run_report_job->ReportJobID;

        // Ping report job until status is 'Done'
        $done = false;
        while(!$done) {
            sleep($this->PING_INTERVAL);

            $report_job = $this->fattail_client->call(
                'GetReportJob',
                ['reportJobId' => $report_job_id]
            )->GetReportJobResult;

            if (strtolower($report_job->Status) === $this->DONE) {
                $done = true;
            }
        }

        // Get the CSV download URL
        $report_url_result = $this->fattail_client->call(
            'GetReportDownloadUrl',
            ['reportJobId' => $report_job_id]
        );
        $report_url = $report_url_result->GetReportDownloadURLResult;

        $csv_path = $dir . $report->SavedReportID . '.csv';

        // Create download directory if it doesn't exist
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        // Download the file
        file_put_contents(
            $csv_path,
            fopen($report_url, 'r')
        );

        $this->logger->info('Finished downloading CSV report.');

        return $csv_path;
    }

    /**
     * Cleans up (deletes) all CSV files within the directory
     * and then deletes the directory.
     *
     * @param $dir The directory to clean up.
     */
    protected
    function clean_up($dir = '') {

        if (is_dir($dir)) {

            // Delete all csv files within the directory
            array_map('unlink', glob($dir . '*.csv'));
        }
    }

    /**
     * Creates an CD entity based on the path and details.
     *
     * @param $path The path for the resource
     * @param $details An array representing the entity data
     *
     * @return A Psr\Http\Message\ResponseInterface object
     */
    protected
    function cd_post($path, $details) {

        $http_response = null;
        try {
            $http_response = $this->edge_client->call(
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
     * Creates an account on CD.
     *
     * @param $name The account name.
     * @param $custom_fields An array of custom fields.
     *
     * @returns A new Account
     */
    private
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
    private
    function create_cd_workspace(
        $account_id,
        $name,
        $template_hash,
        $custom_fields = []
    ) {
        $details = new \stdClass();
        $details->workspaceName = $name;
        $details->workspaceType = $template_hash; // TODO The real hash of the workspace template

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
    private
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
    private
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
     * Finds a Central Desktop user by first name and last name.
     * The names will be formatted to "first last" and compared.
     *
     * @param $first_name The first name of the user.
     *
     * @return The user id.
     */
    private
    function get_cd_user_id_by_name($full_name) {

        $http_response = $this->edge_client->call(
            EdgeClient::METHOD_GET,
            'users'
        );

        $users = json_decode($http_response->getContent())->items;

        $full_name_lower = strtolower($full_name);

        $user = array_filter($users, function ($user) use ($full_name_lower) {
            $user_full_name_lower = strtolower($user->details->fullName);

            return $user_full_name_lower === $full_name_lower;
        });

        return count($user) > 0 ? $user[0]->id : null;
    }

    /**
     * Assigns a user based on their full name to
     * a role by its name.
     *
     * @param $full_name The user's full name.
     * @param $role_name The role's name.
     * @param $workspace_hash The workspace's hash.
     */
    private
    function assign_user_to_role($full_name, $role_hash, $workspace_hash) {

        // Search for the user by name
        $user_hash = $this->get_cd_user_id_by_name($full_name);

        if ($user_hash == null) {
            $this->logger->warning('Failed to find Sales Rep user. ' .
                'Continuing without assigning a user to Salesrep role.');
        }
        else if ($role_hash == null) {
            $this->logger->warning('Failed to find Central Desktop role.' .
                'Make sure a \'Salesrep\' role exists. ' .
                'Continuing without assigning a user to Salesrep role.');
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
     * Converts a value into a value using only arrays.
     * Only works on public members.
     *
     * @param $thing The value to be converted.
     * @return The value using only arrays.
     */
    private
    function convert_to_arrays($thing) {

        return json_decode(json_encode($thing), true);
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

    /**
     * Gets all the cd accounts.
     */
    private
    function get_cd_accounts() {

        $path = 'accounts';
        $http_response = $this->edge_client->call(
                             EdgeClient::METHOD_GET,
                             $path
                         );

        $data = json_decode($http_response->getContent())->items;
        $accounts = [];
        foreach ($data as $account_data) {

            $c_client_id = null;
            if (property_exists($account_data->details, 'customFields')) {
                $custom_fields = $account_data->details->customFields;
                foreach ($custom_fields as $field) {
                    if ($field->fieldApiId === 'c_client_id') {
                        // We only care about the client id
                        $c_client_id = $field->value;
                    }
                }
            }

            $accounts[$account_data->id] = new Account(
                $account_data->id,
                $c_client_id
            );
        }

        return $accounts;
    }

    /**
     * Gets all the cd workspaces.
     */
    private
    function get_cd_workspaces($account_hash) {

        $path = 'accounts/' . $account_hash . '/workspaces';
        $http_response = $this->edge_client->call(
                             EdgeClient::METHOD_GET,
                             $path
                         );

        $workspaces = [];
        $json = json_decode($http_response->getContent());
        if (property_exists($json, 'items')) {

            $data = $json->items;
            foreach ($data as $workspace_data) {

                // Skip deleted workspaces
                if (preg_match('/^deleted.*/', $workspace_data->details->urlShortName)) {
                    continue;
                }

                $c_order_id = null;
                if (property_exists($workspace_data->details, 'customFields')) {
                    $custom_fields = $workspace_data->details->customFields;
                    foreach ($custom_fields as $field) {
                        if ($field->fieldApiId === 'c_order_id') {
                            // We only care about the order id
                            $c_order_id = $field->value;
                        }
                    }
                }

                $workspaces[$workspace_data->id] = new Workspace(
                    $workspace_data->id,
                    $c_order_id
                );
            }
        }

        return $workspaces;
    }

    /**
     * Gets all the cd milestones.
     *
     * @param $workspace_hash The CD workspace hash
     * @return An array of Milestones belonging to workspace
     */
    private
    function get_cd_milestones($workspace_hash) {

        $path = 'workspaces/' . $workspace_hash . '/milestones';
        $http_response = $this->edge_client->call(
                             EdgeClient::METHOD_GET,
                             $path
                         );
        $milestones = [];
        $json = json_decode($http_response->getContent());
        if (property_exists($json, 'items')) {

            $data = $json->items;
            foreach ($data as $milestone_data) {

                $c_drop_id = null;
                if (property_exists($milestone_data->details, 'customFields')) {
                    $custom_fields = $milestone_data->details->customFields;
                    foreach ($custom_fields as $field) {
                        if ($field->fieldApiId === 'c_drop_id') {
                            // We only care about the drop id
                            $c_drop_id = $field->value;
                        }
                    }
                }

                $milestones[$milestone_data->id] = new Milestone(
                    $milestone_data->id,
                    $c_drop_id
                );
            }
        }

        return $milestones;
    }

    /**
     * Finds a single instance of a CD account by c_client_id.
     *
     * @param $c_client_id The FatTail client id
     * @return Account with $c_client_id or null if not found
     */
    private
    function find_account_by_c_client_id($c_client_id) {

        foreach ($this->cd_accounts as $account) {

            if ($account->c_client_id == $c_client_id) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Finds and returns the id of the order dynamic property
     * in FatTail.
     *
     * @param $name The name of the order dynamic property.
     * @return The order dynamic property id if found, else null
     */
    private
    function get_fattail_order_dynamic_property_id($name) {

        // Find the dynamic property id for the workspace id
        $order_workspace_property_id = null;
        $order_dynamic_properties_list = $this->fattail_client->call(
            'GetDynamicPropertiesListForOrder'
        )->GetDynamicPropertiesListForOrderResult;
        foreach ($order_dynamic_properties_list->DynamicProperty as $prop) {
            if ($prop->Name === $name) {
                return $prop->DynamicPropertyID;
            }
        }

        return null;
    }

    /**
     * Finds and returns the id of the drop dynamic property
     * in FatTail.
     *
     * @param $name The name of the drop dynamic property.
     * @return The drop dynamic property id if found, else null
     */
    private
    function get_fattail_drop_dynamic_property_id($name) {

        // Find the dynamic property id for the milestone id
        $drop_milestone_property_id = null;
        $drop_dynamic_properties_list = $this->fattail_client->call(
            'GetDynamicPropertiesListForDrop'
        )->GetDynamicPropertiesListForDropResult;
        foreach ($drop_dynamic_properties_list->DynamicProperty as $prop) {
            if ($prop->Name === $name) {
                return $prop->DynamicPropertyID;
            }
        }

        return null;
    }
}
