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

use League\Csv\Reader;
use Psr\Log\LoggerAwareTrait;

class SyncService {
    use LoggerAwareTrait;

    protected $edge_client    = null;
    protected $fattail_client = null;

    private $PING_INTERVAL    = 5; // In seconds
    private $DONE             = 'done';
    private $tmp_dir          = 'tmp/';

    private $SALES_REP_ROLE = 'Salesrep';

    public
    function __construct(
        EdgeClient $edge_client,
        FatTailClient $fattail_client,
        $tmp_dir = ''
    ) {
        $this->edge_client    = $edge_client;
        $this->fattail_client = $fattail_client;
        $this->tmp_dir        = $tmp_dir;
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
            $this->logger->info("Unable to find requested report. Exiting.\n");
            exit;
        }

        $csv_path = $this->download_report_csv(
            $report,
            $this->tmp_dir
        );

        $reader = Reader::createFromPath($csv_path);
        $rows = $reader->fetchAll();

        // Find the dynamic property id for the workspace id
        $order_workspace_property_id = null;
        $order_dynamic_properties_list = $this->fattail_client->call(
            'GetDynamicPropertiesListForOrder'
        )->GetDynamicPropertiesListForOrderResult;
        foreach ($order_dynamic_properties_list->DynamicProperty as $prop) {
            if ($prop->Name === 'H_CD_Workspace_ID') {
                $order_workspace_property_id = $prop->DynamicPropertyID;
                break;
            }
        }

        // Find the dynamic property id for the milestone id
        $drop_milestone_property_id = null;
        $drop_dynamic_properties_list = $this->fattail_client->call(
            'GetDynamicPropertiesListForDrop'
        )->GetDynamicPropertiesListForDropResult;
        foreach ($drop_dynamic_properties_list->DynamicProperty as $prop) {
            if ($prop->Name === 'H_CD_Milestone_ID') {
                $drop_milestone_property_id = $prop->DynamicPropertyID;
                break;
            }
        }

        // Create mapping of column name with column index
        // Not necessary, but makes it easier to work with the data.
        // Can remove if optimization issues arise because of this
        $col_map = [];
        foreach ($rows[0] as $index => $name) {
            $col_map[$name] = $index;
        }

        $this->logger->info("Processing CSV and syncing data.\n");
        
        // Iterate over CSV and process data
        // Skip the first and last rows since they
        // dont have the data we need
        for ($i = 1, $len = count($rows) - 1; $i < $len; $i++) {
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

            /*$order->OrderDynamicProperties = [
                'DynamicPropertyValue' => [
                    [
                        'DynamicPropertyID' => $order_workspace_property_id,
                        'Value' => 'asdfasdsdf'
                    ]
                ]
            ];
            $order_array = $this->convert_to_arrays($order);
            $this->fattail_client->call(
                'UpdateOrder',
                ['order' => $order_array]
            );

            $order = $this->fattail_client->call(
                'GetOrder',
                ['orderId' => $order_id]
            )->GetOrderResult;*/

            //print_r($order);

            //exit;
            $client = $this->sync_client_to_account($client, $rows[$i]);

            // Check client to account sync
            $account_hash = $client->ExternalID;

            // Check order to workspace sync
            $workspace_hash = $rows[$i][$col_map['(Campaign) CD Workspace ID']];
            $order = $this->sync_order_to_workspace(
                $order,
                $account_hash,
                $workspace_hash,
                $rows[$i],
                $col_map
            );
            /*$workspace_hash = $order->OrderDynamicProperties; // TODO Get the CD Workspace ID

            $drop = $this->sync_drop_to_milestone(
                $drop,
                $drop_id,
                $workspace_hash,
                $rows[$i],
                $col_map
            );
            $milestone_hash = $drop->DropDynamicProperties; //TODO Get milestone hash from dynamic properties*/
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

            // Delete all files within the directory
            array_map('unlink', glob($dir . '*.csv'));

            // Delete the directory
            rmdir($dir);
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
    function create_cd_entity($path, $details) {

        $http_response = null;
        try {
            $http_response = $this->edge_client->call(
                EdgeClient::METHOD_POST,
                $path,
                [],
                $details
            );
        }
        catch (\GuzzleHttp\Exception\ClientException $e) {
            // Failed to create new entity
            $this->logger->error('Bad request to Edge API');
            $this->logger->error($e);
            $this->logger->error('Exiting.');
            exit;
        }
        catch (\GuzzleHttp\Exception\ServerException $e) {
            // Failed to create new entity
            $this->logger->error('Server error on Edge API');
            $this->logger->error($e);
            $this->logger->error('Exiting.');
            exit;
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
     * @returns The account hash of the new account.
     */
    private
    function create_cd_account($name, $custom_fields = []) {

        $details = new \stdClass();
        $details->accountName = $name;

        // Prepare data for request
        $path = 'accounts';
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        $http_response = $this->create_cd_entity($path, $details);

        // Return the id of the account
        return $http_response->getBody()->getContents();
    }

    /**
     * Creates a workspace on CD.
     *
     * @param $account_id The CD account id this workspace will be under.
     * @param $name The name of the workspace.
     * @param $custom_fields An array of custom fields.
     * @param $order_id The FatTail order id.
     * @param $status The FatTail order status.
     * @param $start_date The FatTail campaign start date.
     * @param $end_date The FatTail campaign end date.
     *
     * @return TODO
     */
    private
    function create_cd_workspace(
        $account_id,
        $name,
        $custom_fields = []
    ) {
        $details = new \stdClass();
        $details->workspaceName = $name;
        $details->workspaceType = 'WzIxLDQ2NDA1MV0'; // TODO The real hash of the workspace template

        // Prepare data for request
        $path = 'accounts/' . $account_id . '/workspaces';
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        $http_response = $this->create_cd_entity($path, $details);

        // Return the id of the workspace
        return $http_response->getBody()->getContents();
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
     * @return The milestone hash.
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

        $http_response = $this->create_cd_entity($path, $details);

        // Return the id of the workspace
        return $http_response->getBody()->getContents();
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
     * @return The milestone hash.
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
        $details->description = $description;
        $details->start_date = $start_date;
        $details->end_date = $end_date;
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        // Create a new milestone
        $path = 'milestones/' . $milestone_id . '/updateDetail';

        $http_response = $this->create_cd_entity($path, $details);

        // Check if create wasn't successful
        if (
            $http_response == null ||
            $http_response->getStatusCode() !== 201
        ) {
            // TODO
        }

        // Call milestone endpoint to get the latest hash
        $response = $this->edge_client->call(
            EdgeClient::METHOD_GET,
            $path
        );
        $workspaces = json_decode($response->getBody());

        return $milestones_id;
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

        $users = json_decode($http_response->getBody())->items;

        $full_name_lower = strtolower($full_name);

        $user = array_filter($users, function ($user) use ($full_name_lower) {
            $user_full_name_lower = strtolower($user->details->fullName);

            return $user_full_name_lower === $full_name_lower;
        });

        return count($user) > 0 ? $user[0]->id : null;
    }

    /**
     * Gets a role by name.
     *
     * @param $name The name of the role.
     *
     * @return The role id.
     */
    private
    function get_cd_role_id_by_name($name) {
        
        $http_response = $this->edge_client->call(
            EdgeClient::METHOD_GET,
            'roles'
        );

        $roles = json_decode($http_response->getBody())->items;

        $name_lower = strtolower($name);

        $role = array_filter($roles, function ($role) use ($name_lower) {
            $title_lower = strtolower($role->details->title);

            return $title_lower === $name_lower;
        });

        return count($role) > 0 ? $role[0]->id : null;
    }

    /**
     * Syncs FatTail clients with Central Desktop accounts.
     *
     * @param $client The FatTail client which
     *                corresponds to a CD account.
     * @param $rows The report data.
     *
     * @return The (un)updated client.
     */
    private
    function sync_client_to_account($client, $rows) {
        
        $account_hash = $client->ExternalID;
        if ($account_hash === '') {

            // Create CD account
            $custom_fields = [
                'c_client_id' => $client->ClientID
            ];
            $account_hash = $this->create_cd_account(
                $client->Name,
                $custom_fields
            );

            // Update client external id with new account hash
            $client->ExternalID = $account_hash;
        }
            
        // Update FatTail client
        /*$client_array = $this->convert_to_arrays($client);
        $this->fattail_client->call(
            'UpdateClient',
            ['client' => $client_array]
        );*/

        return $client;
    }

    /**
     * Syncs FatTail orders with Central Desktop workspaces.
     *
     * @param $order The FatTail order which
     *                     corresponds to a CD workspace.
     * @param $account_hash The hash of the CD account.
     * @param $workspace_hash The hash of the CD workspace.
     * @param $row The report data.
     * @param $col_map The report data column mapping.
     *
     * @return The (un)updated order.
     */
    private
    function sync_order_to_workspace(
        $order,
        $account_hash,
        $workspace_hash,
        $row,
        $col_map
    ) {
        
        if ($workspace_hash === '') {

            $custom_fields = [
                'c_order_id'            => $order->OrderID,
                'c_campaign_status'     => $row[$col_map['IO Status']],
                'c_campaign_start_date' => $row[$col_map['Campaign Start Date']],
                'c_campaign_end_date'   => $row[$col_map['Campaign End Date']]
            ];
            $workspace_hash = $this->create_cd_workspace(
                $account_hash,
                $row[$col_map['Campaign Name']],
                $custom_fields
            );

            // Update the CD Workspace ID on FatTail order
            // TODO This is not correct, working on finding something that
            // works
            $old_properties = [];
            if (
                property_exists($order, 'OrderDynamicProperties') &&
                $order->OrderDynamicProperties !== null
            ) {
                $old_properties = $this->convert_to_arrays(
                    $order->OrderDynamicProperties
                );
            }
            $new_properties = array_merge($old_properties, [
                'CD Workspace ID' => $workspace_hash
            ]);
            $order->OrderDynamicProperties = $new_properties;

            // Get sales rep information and set role for account

            // Splitting name, assuming only first and last name in
            // 'Last,' First format
            $sales_rep_name = $row[$col_map['Sales Rep']];
            $name_parts = explode(', ', $sales_rep_name);
            $full_name = strtolower($name_parts[1] . ' ' . $name_parts[0]);

            $this->assign_user_to_role(
                $full_name,
                $this->SALES_REP_ROLE,
                $workspace_hash
            );
        }

        // Update FatTail order
        /*$order_array = $this->convert_to_arrays($order);
        $this->fattail_client->call(
            'UpdateOrder',
            ['order' => $order_array]
        );*/

        return $order;
    }

    /**
     * Syncs FatTail drops with Central Desktop milestones.
     *
     * @param $drop The FatTail drop which
     *              corresponds to a CD milestone.
     * @param $drop_id The id of the FatTail drop.
     * @param $workspace_hash The hash of the CD workspace.
     * @param $row The report data.
     * @param $col_map The report data column mapping.
     *
     * @return The (un)updated drop.
     */
    private
    function sync_drop_to_milestone($drop, $drop_id, $workspace_hash, $row, $col_map) {

            // Check drop to milestone sync
            $milestone_hash = $row[$col_map['(Drop) CD Milestone ID']];
            $custom_fields = [
                'c_drop_id'              => $drop_id,
                'c_custom_unit_features' => $row[$col_map['(Drop) Custom Unit Features']],
                'c_kpi'                  => $row[$col_map['(Drop) Line Item KPI']],
                'c_drop_cost_new'        => $row[$col_map['Sold Amount']]
            ];
            if ($milestone_hash === '') {

                /*$milestone_hash = $this->create_cd_milestone(
                    $workspace_hash,
                    $row[$col_map['Position Path']],
                    $row[$col_map['Drop Description']],
                    $row[$col_map['Start Date']],
                    $row[$col_map['End Date']],
                    $custom_fields
                );*/

            }
            else {
                // Update milestone with latest drop data

                /*$milestone_hash = $this->update_cd_milestone(
                    $milestone_hash,
                    $row[$col_map['Position Path']],
                    $row[$col_map['Drop Description']],
                    $row[$col_map['Start Date']],
                    $row[$col_map['End Date']],
                    $custom_fields
                );*/

                // TODO Gets sales rep information
                // and set role for account
            }

            // Update the CD Milestone ID on FatTail drop
            // TODO This is not correct, working on finding something that
            // works
            $old_properties = [];
            if (
                property_exists($drop, 'DropDynamicProperties') &&
                $drop>DropDynamicProperties !== null
            ) {
                $old_properties = $this->convert_to_arrays(
                    $drop>DropDynamicProperties
                );
            }
            $new_properties = array_merge($old_properties, [
                'CD Milestone ID' => $milestone_hash
            ]);
            $drop->DropDynamicProperties = $new_properties;

            // Update FatTail drop
            /*$drop_array = $this->convert_to_arrays($drop);
            $this->fattail_client->call(
                'UpdateDrop',
                ['drop' => $drop_array]
            );*/

            return $drop;
    }

    /**
     * Assigns a user based on their full name to
     * a role by its name.
     *
     * @param $full_name The user's full name.
     * @param $role_name The role's name.
     */
    private
    function assign_user_to_role($full_name, $role_name, $workspace_hash) {

        // Search for the user by name
        $user_hash = $this->get_cd_user_id_by_name($full_name);

        $role_hash = $this->get_cd_role_id_by_name($role_name);

        if ($user_hash === null) {
            $this->logger->info('Failed to find Sales Rep user. ' .
                'Continuing without assigning a user to Salesrep role.');
        }
        else if ($role_hash == null) {
            $this->logger->info('Failed to find Central Desktop role.' .
                'Make sure a \'Salesrep\' role exists. ' .
                'Continuing without assigning a user to Salesrep role.');
        }
        else {

            // Add user to 'Salesrep' workspace role
            $add_user_to_role_path = sprintf(
                'workspaces/%s/roles/%s/addUser',
                $workspace_hash,
                $role_hash
            );
            $body = new \stdClass();
            $body->userIds = [
                $user_hash
            ];
            $body->clearExisting = false;
            //$http_response = $this->edge_client->call(
            //    EdgeClient::METHOD_POST,
            //    $add_user_to_role_path,
            //    [],
            //    $body
            //);

            // TODO check if add user to role was successful
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
}
