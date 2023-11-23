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
    private $url;

    /**
     * Keeps a list of failed scenarios.
     */
    private $failedScenarios;

    /**
     * Screenshots service.
     */
    private $screenshotService;

    /**
     * Constructor for Dashboard Notifier.
     */
    public function __construct($params)
    {
        $this->params = $params;
        $this->url = $params['url'];
    }

    /**
     * Getter for $url.
     */
    public function getUrl()
    {
        return trim($this->url, '/');
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
     * @param string $endpoint
     *   The endpoint.
     */
    public function post(array $payload, $endpoint)
    {
        if (empty($payload)) {
            return;
        }

        $url = $this->getUrl();

        // Get auth token once.
        if (empty($this->params['token'])) {
            $this->params['token'] = $this->doRequest("$url/session/token", '');
        }

        $payload = json_encode($payload);
        if (!empty($endpoint) && !empty($this->params['token'])) {
            $headers = [
                'X-CSRF-Token: ' . $this->params['token'],
            ];
            $this->doRequest("$url/$endpoint", $payload, $headers);
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
                    // Check for screenshot service.
                    if (!empty($details['screenshotService'])) {
                        $this->screenshotService = $details['screenshotService'];
                    }
                    $payload = $this->getScenarioFailedPayload($event, $details['error_message']);
                }
                break;

            default:
                var_dump("Event $event is not implemented yet.");
        }

        var_dump(json_encode($payload, JSON_PRETTY_PRINT));

        $this->post($payload, 'behat/results/upload');
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
     * @param string $error_message
     *   The step exception.
     *
     * @return string[]
     */
    public function getScenarioFailedPayload(AfterScenarioTested $event, $error_message) {
        $this->processDetails($event, $error_message);

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
     * @param string $error_message
     *   The step exception.
     */
    private function processDetails(AfterScenarioTested $event, $error_message) {
        // Process steps.
        /** @var Behat\Gherkin\Node\StepNode $step */
        $steps = [];
        foreach ($event->getScenario()->getSteps() as $step) {
            $steps[] = $step->getKeyword() . ' ' . $step->getText();
        }

        // Process screenshots.
        $screenshots = [];
        if (!empty($this->screenshotService) && method_exists($this->screenshotService, 'getImages')) {
            $files = $this->screenshotService->getImages();
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
            'error_message' => $error_message,
            'screenshots' => $screenshots,
        ];
    }
}
