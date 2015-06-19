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
            // TODO
            $this->logger->info("Unable to find requested report. Exiting.\n");
            exit;
        }

        $csv_path = $this->download_report_csv(
            $report,
            $this->tmp_dir
        );

        $reader = Reader::createFromPath($csv_path);
        $rows = $reader->fetchAll();

        // Create mapping of column name with column index
        // Not necessary, but makes it easier to work with the data
        // can remove if optimization issues arise
        $col_map = [];
        foreach ($rows[0] as $index => $name) {
            $col_map[$name] = $index;
        }

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

            // Check client to account sync
            $account_hash = $client->ExternalID;
            if ($account_hash === "") {

                $custom_fields = [
                    'c_client_id' => $client_id
                ];
                /*$account_hash = $this->create_cd_account(
                    $client->Name,
                    $custom_fields
                );*/

                // Update client external id with new account hash
                $client->ExternalID = $account_hash;
            }

            // Check order to workspace sync
            $workspace_hash = $rows[$i][$col_map['(Campaign) CD Workspace ID']];
            if ($workspace_hash === "") {

                $custom_fields = [
                    'c_order_id'            => $order_id,
                    'c_campaign_status'     => $rows[$i][$col_map['IO Status']],
                    'c_campaign_start_date' => $rows[$i][$col_map['Campaign Start Date']],
                    'c_campaign_end_date'   => $rows[$i][$col_map['Campaign End Date']]
                ];
                /*$workspace_hash = $this->create_cd_workspace(
                    $account_hash,
                    $rows[$i][$col_map['Campaign Name']]
                );*/

                // Update the CD Workspace ID on FatTail order
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

                // TODO Gets sales rep information
                // and set role for account
            }

            // Check drop to milestone sync
            $milestone_hash = $rows[$i][$col_map['(Drop) CD Milestone ID']];
            $custom_fields = [
                'c_drop_id'              => $drop_id,
                'c_custom_unit_features' => $rows[$i][$col_map['(Drop) Custom Unit Features']],
                'c_kpi'                  => $rows[$i][$col_map['(Drop) Line Item KPI']],
                'c_drop_cost_new'        => $rows[$i][$col_map['Sold Amount']]
            ];
            if ($milestone_hash === "") {

                /*$milestone_hash = $this->create_cd_milestone(
                    $workspace_hash,
                    $rows[$i][$col_map['Position Path']],
                    $rows[$i][$col_map['Drop Description']],
                    $rows[$i][$col_map['Start Date']],
                    $rows[$i][$col_map['End Date']],
                    $custom_fields
                );*/


                // TODO Gets sales rep information
                // and set role for account
            }
            else {
                // Update milestone with latest drop data

                /*$milestone_hash = $this->update_cd_milestone(
                    $milestone_hash,
                    $rows[$i][$col_map['Position Path']],
                    $rows[$i][$col_map['Drop Description']],
                    $rows[$i][$col_map['Start Date']],
                    $rows[$i][$col_map['End Date']],
                    $custom_fields
                );*/

                // TODO Gets sales rep information
                // and set role for account
            }

            // Update the CD Milestone ID on FatTail drop
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

            // Update FatTail client
            /*$client_array = $this->convert_to_arrays($client);
            $this->fattail_client->call(
                'UpdateClient',
                ['client' => $client_array]
            );*/

            // Update FatTail order
            /*$order_array = $this->convert_to_arrays($order);
            $this->fattail_client->call(
                'UpdateOrder',
                ['order' => $order]
            );*/

            // Update FatTail drop
            /*$drop_array = $this->convert_to_arrays($drop);
            $this->fattail_client->call(
                'UpdateDrop',
                ['drop' => $drop]
            );*/
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
    protected
    function create_cd_account($name, $custom_fields = []) {

        $details = new \stdClass();
        $details->accountName = $name;

        // Prepare data for request
        $path = 'accounts';
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        $http_response = $this->create_cd_entity($path, $details);

        // Check if create wasn't successful
        if (
            $http_response == null ||
            $http_response->getStatusCode() !== 201
        ) {
            // TODO
        }

        // Call accounts endpoint to get the latest hash/id
        $response = $this->edge_client->call(
            EdgeClient::METHOD_GET,
            $path
        );
        $accounts = json_decode($response->getBody());

        // The last inserted account should be
        // the new one we just created
        return $accounts->lastRecord;
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
    protected
    function create_cd_workspace(
        $account_id,
        $name,
        $custom_fields = []
    ) {
        $details = new \stdClass();
        $details->workspaceName = $name;

        // Prepare data for request
        $path = 'accounts/' . $account_id . '/workspaces';
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        $http_response = $this->create_cd_entity($path, $details);

        // Check if create wasn't successful
        if (
            $http_response == null ||
            $http_response->getStatusCode() !== 201
        ) {
            // TODO
        }

        // TODO on successful creation of workspace
        /*$response = $this->edge_client->call(
            EdgeClient::METHOD_GET,
            $path
        );
        $workspaces = json_decode($response->getBody());

        return $workspaces->lastRecord;*/
        return $http_response; // TODO Temporary
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
        $custom_fields
    ) {
        $details = new \stdClass();
        $details->title = $name;
        $details->description = $description;
        $details->start_date = $start_date;
        $details->end_date = $end_date;
        $details->customFields = $this->create_cd_custom_fields($custom_fields);

        // Create a new milestone
        $path = 'workspaces/' . $workspace_id . '/milestones';

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
        $milestones = json_decode($response->getBody());

        return $milestones->lastRecord;
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
