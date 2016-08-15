<?php

namespace CentralDesktop\FatTail\Services;

use CentralDesktop\FatTail\Services\Client\FatTailClient;

use JmesPath;
use Psr\Log\LoggerAwareTrait;

class FatTailService {
    use LoggerAwareTrait;

    private $client = null;

    public
    function __construct(FatTailClient $client) {
        $this->client = $client;
    }

    /**
     * Gets the saved report information by
     * saved report name.
     *
     * @param $name The name of the saved report.
     * @return Saved report object
     */
    public
    function get_saved_report_info_by_name($name) {

        // Get all saved reports
        $report_list_result = $this->get_saved_report_list();
        $report_list        = $report_list_result
                              ->GetSavedReportListResult
                              ->SavedReport;

        // Find the specific report
        $report = null;
        foreach ($report_list as $report_item) {
            if ($report_item->Name === $name) {
                $report = $report_item;
            }
        }

        return $report;
    }

    /**
     * Gets a list of saved reports from FatTail
     *
     * @return An array of saved reports.
     */
    public
    function get_saved_report_list() {

        return $this->client->call('GetSavedReportList');
    }

    /**
     * Gets a saved report from FatTail by saved report id.
     *
     * @param $report_id The saved report id.
     * @return The FatTail saved report object.
     */
    public
    function get_saved_report_by_id($report_id) {

        // Get individual report details
        return $this->client->call(
            'GetSavedReportQuery',
            $this->array_to_object([ 'savedReportId' => $report_id ])
        )->GetSavedReportQueryResult;
    }

    /**
     * Runs a FatTail report job.
     *
     * @param $report_job The report job to run.
     * @return The FatTail report id.
     */
    public
    function run_report_job($report_job) {

        $run_report_job = $this->client->call(
            'RunReportJob',
            $this->array_to_object(['reportJob' => $report_job])
        )->RunReportJobResult;

        return $run_report_job->ReportJobID;
    }

    /**
     * Gets a report job from FatTail by report job id.
     *
     * @param $job_id The report job id.
     * @return The FatTail report job object.
     */
    public
    function get_report_job_by_id($job_id) {

        return $this->client->call(
            'GetReportJob',
            $this->array_to_object(['reportJobId' => $job_id])
        )->GetReportJobResult;
    }

    /**
     * Gets a report download URL from FatTail by report job id.
     *
     * @param $job_id The report job id.
     * @return The FatTail report URL object.
     */
    public
    function get_report_url_by_id($job_id) {

        $report_url_result = $this->client->call(
            'GetReportDownloadUrl',
            $this->array_to_object(['reportJobId' => $job_id])
        );

        return $report_url_result->GetReportDownloadURLResult;
    }

    public
    function get_clients() {
        return $this->client->call(
            'GetClientList'
        )->GetClientListResult->Client;
    }

    /**
     * Queries FatTail for a client by its id.
     *
     * @param $client_id The FatTail client id.
     *
     * @return The FatTail client object.
     */
    public
    function get_client_by_id($client_id) {

        return $this->client->call(
            'GetClient',
            $this->array_to_object(['clientId' => $client_id])
        )->GetClientResult;
    }

    /**
     * Queries FatTail for an order by its id.
     *
     * @param $order_id The FatTail order id.
     *
     * @return The FatTail order object.
     */
    public
    function get_order_by_id($order_id) {

        return $this->client->call(
            'GetOrder',
            $this->array_to_object(['orderId' => $order_id])
        )->GetOrderResult;
    }

    /**
     * Queries FatTail for a drop by its id.
     *
     * @param $drop_id The FatTail drop id.
     *
     * @return The FatTail drop object.
     */
    public
    function get_drop_by_id($drop_id) {

        return $this->client->call(
            'GetDrop',
            $this->array_to_object(['dropId' => $drop_id])
        )->GetDropResult;
    }

    /**
     * Updates a FatTail client.
     *
     * @param $client The FatTail client object.
     *
     * @return The response object.
     */
    public
    function update_client($client) {

        return $this->client->call(
            'UpdateClient',
            $this->array_to_object(['client' => $client])
        );
    }

    /**
     * Updates a FatTail order.
     *
     * @param $order The FatTail order object.
     *
     * @return The response object.
     */
    public
    function update_order($order) {

        return $this->client->call(
            'UpdateOrder',
            $this->array_to_object(['order' => $order])
        );
    }

    /**
     * Updates a FatTail drop.
     *
     * @param $drop The FatTail drop object.
     *
     * @return The response object.
     */
    public
    function update_drop($drop) {

        if (property_exists($drop, 'ParentDropID')) {
            $method     = 'UpdatePackageComponentDrops';
            $parameters = ['componentDrops' => [$drop]];
        }
        else {
            $method     = 'UpdateDrop';
            $parameters = ['drop' => $drop];
        }

        return $this->client->call(
            $method,
            $this->array_to_object($parameters)
        );
    }

    /**
     * Finds and returns the id of the order dynamic property
     * in FatTail.
     *
     * @param $name string name of the order dynamic property.
     * @return integer order dynamic property id if found, else null
     */
    public
    function get_order_dynamic_property_id($name) {

        // Find the dynamic property id for the workspace id
        $order_workspace_property_id = null;
        $order_dynamic_properties_list = $this->client->call(
            'GetDynamicPropertiesListForOrder'
        )->GetDynamicPropertiesListForOrderResult;
        $dynamic_property_id = JmesPath\Env::search(
            "DynamicProperty[?Name=='$name'].DynamicPropertyID | [0]",
            $order_dynamic_properties_list
        );

        return $dynamic_property_id;
    }


    /**
     * Finds and returns the id of the drop dynamic property
     * in FatTail.
     *
     * @param $name The name of the drop dynamic property.
     * @return The drop dynamic property id if found, else null
     */
    public
    function get_drop_dynamic_property_id($name) {

        // Find the dynamic property id for the milestone id
        $drop_milestone_property_id = null;
        $drop_dynamic_properties_list = $this->client->call(
            'GetDynamicPropertiesListForDrop'
        )->GetDynamicPropertiesListForDropResult;
        $dynamic_property_id = JmesPath\Env::search(
            "DynamicProperty[?Name=='$name'].DynamicPropertyID | [0]",
            $drop_dynamic_properties_list
        );

        return $dynamic_property_id;
    }

    /**
     * Updates or adds a DynamicPropertyValue to
     * an array of DynamicPropertyValues.
     *
     * @param $properties An array of FatTail DynamicPropertyValues.
     * @param $id The FatTail DynamicPropertyID.
     * @param $value The FatTail property value.
     * @return The updated array of dynamic property values.
     */
    public
    function update_dynamic_properties($properties = [], $id, $value) {

        if (!is_array($properties)) {
            // Sometimes giving a single object
            // instead of an array
            $properties = [$properties];
        }

        $found = false;
        foreach ($properties as $property) {
            if ($property->DynamicPropertyID == $id) {
                $property->Value = $value;
                $found = true;
            }
        }

        if (!$found) {
            $property = new \stdClass();
            $property->DynamicPropertyID = $id;
            $property->Value = $value;
            $properties[] = $property;
        }

        return $properties;
    }

    /**
     * Converts an array and sub arrays to objects.
     *
     * @param array $array
     * @return mixed
     */
    private
    function array_to_object(array $array = []) {
        return json_decode(json_encode($array), false);
    }
}