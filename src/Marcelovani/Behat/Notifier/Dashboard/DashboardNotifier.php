<?php

namespace Marcelovani\Behat\Notifier\Dashboard;

use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;

/**
 * This class sends notification to a dashboard.
 */
class DashboardNotifier
{

    /**
     * Stores extension params.
     */
    private $params;

    /**
     * The url to be used in the POST request.
     */
    private $endpoint;

    /**
     * Keeps a list of failed scenarios.
     */
    private $failedScenarios;

    /**
     * Constructor for Dashboard Notifier.
     */
    public function __construct($params)
    {
        $this->params = $params;
        $this->endpoint = $params['endpoint'];
    }

    /**
     * Getter for $url.
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * Helper to do the post request.
     *
     * @param string $url
     *   The Webhook.
     * @param string $json
     *   The message to send in json format.
     * @param array $headers
     *   The headers.
     * @return string
     *   The response.
     * @todo Use guzzle.
     */
    private function doRequest($url, $json, $headers = [])
    {
        $ch = curl_init();

        $headers = array_merge(['Content-type: application/json'], $headers);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check response code.
        if ($response_code != 200) {
            $msg = "Dashboard returned invalid response code for endpoint $url. Json $json. Response code $response_code.";
            throw new \Exception($msg);
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $header_size);

        return $body;
    }

    /**
     * Posts messsage to MS Teams.
     *
     * @param array $payload
     *   Payload with parameters.
     */
    private function post(array $payload)
    {
        if (empty($payload)) {
            return;
        }

        var_dump(json_encode($payload, JSON_PRETTY_PRINT));

        // Get auth token once.
        if (empty($this->params['token'])) {
            $url = $this->params['endpoint'];
            $remove = basename($url);
            $url = str_replace($remove, '', $url);
            $url .= 'session/token';
            $this->params['token'] = $this->doRequest($url, '');
        }

        $payload = json_encode($payload);
        $endpoint = $this->getEndpoint();
        if (!empty($endpoint) && !empty($this->params['token'])) {
            $headers = [
                'X-CSRF-Token: ' . $this->params['token'],
            ];
            $this->doRequest($endpoint, $payload, $headers);
        }
    }

    /**
     * Prepares and sends the payload.
     *
     * @param array $details
     *   The event details.
     */
    public function notify($details)
    {
        $payload = [];
        $event = $details['event'];

        // Send notification.
        switch ($details['eventId']) {
            case 'onBeforeSuiteTested';
                $this->failedScenarios = [];
                $payload = $this->getSuiteStartedPayload();
                break;

            case 'onAfterSuiteTested';
                $payload = $this->getSuiteFinishedPayload();
                break;

            case 'onAfterScenarioTested';
                if (!$event->getTestResult()->isPassed()) {
                    // @todo Fix: This will clear previous failed scenarios for the current job in the Dashboard.
                    // Only at the end, it will show all failed scenarios.
                    // Perhaps we should always send all failing scenarios in an array.
                    $payload = $this->getScenarioFailedPayload($event);
                }
                break;

            default:
                var_dump("Event $event is not implemented yet.");
        }

        $this->post($payload);
    }

    /**
     * Helper to get the payload for Suite start event.
     *
     * @return string[]
     */
    public function getSuiteStartedPayload() {
        // Get auth token.
        return [
            'event' => 'suite_started',
        ];
    }

    /**
     * Helper to get the payload for Suite finished event.
     *
     * @return string[]
     */
    public function getSuiteFinishedPayload() {
        $payload = [
            'event' => 'suite_finished',
            'outcome' => 'passed',
        ];

        if ($this->failedScenarios) {
            $payload['outcome'] = 'failed';
            $payload['scenarios'] = $this->failedScenarios;
        }

        return $payload;
    }

    /**
     * Helper to get the payload for failed scenarios.
     *
     * @param AfterScenarioTested $event
     *   The suite event.
     *
     * @return string[]
     */
    public function getScenarioFailedPayload(AfterScenarioTested $event) {
        $this->processDetails($event);

        return [
            'event' => 'scenario_failed',
            'scenarios' => $this->failedScenarios,
        ];
    }

    /**
     * Helper to process and store details.
     *
     * @param AfterScenarioTested $event
     *   The suite event.
     */
    private function processDetails(AfterScenarioTested $event) {
        // Process steps.
        /** @var Behat\Gherkin\Node\StepNode $step */
        $steps = [];
        foreach ($event->getScenario()->getSteps() as $step) {
            $steps[] = $step->getKeyword() . ' ' . $step->getText();
        }

        // Process screenshots.
        $screenshots = [];
        if (!empty($details['screenshotService'])) {
            $screenshotService = $details['screenshotService'];
            $files = $screenshotService->getImages();
            if (!empty($files)) {
                array_reverse($files);
            }
            $screenshots = $files;
        }

        // Store the details.
        $feature = $event->getFeature()->getTitle();
        $scenario = $event->getScenario()->getTitle();
        $this->failedScenarios[$feature][$scenario] = [
            'description' => $event->getFeature()->getDescription(),
            'steps' => $steps,
            'feature_file' => $event->getFeature()->getFile(),
            'line' => $event->getScenario()->getLine(),
            'screenshots' => $screenshots,
        ];
    }
}
