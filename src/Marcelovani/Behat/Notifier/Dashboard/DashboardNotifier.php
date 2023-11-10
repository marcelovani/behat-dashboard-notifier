<?php

namespace Marcelovani\Behat\Notifier\Dashboard;

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
     * @todo Use guzzle.
     */
    private function doRequest($url, $json)
    {
        $ch = curl_init();

        $header = array();
        $header[] = 'Content-type: application/json';

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check response code.
        if ($response_code != 200) {
            $msg = "Dashboard returned invalid response code for endpoint $url. Response code $response_code.";
            throw new Exception($msg);
        }
    }

    /**
     * Posts messsage to MS Teams.
     *
     * @param array $payload
     *   Payload with parameters.
     */
    private function post(array $payload)
    {
        var_dump(json_encode($payload, JSON_PRETTY_PRINT));
        $payload = json_encode($payload);
        $endpoint = $this->getEndpoint();
        if (!empty($endpoint) && $payload) {
            $this->doRequest($endpoint, $payload);
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

                // Prepare payload.
                $payload = [
                    'event' => 'suite_started',
                ];
                break;

            case 'onAfterSuiteTested';
                // Prepare payload.
                $payload = [
                    'event' => 'suite_finished',
                    'outcome' => 'passed',
                ];

                if ($this->failedScenarios) {
                    $payload['outcome'] = 'failed';
                    $payload['scenarios'] = $this->failedScenarios;
                }
                break;

            case 'onAfterScenarioTested';
                if (!$event->getTestResult()->isPassed()) {

                    // Prepare payload.
                    $payload = [
                        'event' => 'scenario_failed',
                        'feature_file' => $event->getFeature()->getFile(),
                        'feature' => $event->getFeature()->getTitle(),
                        'description' => $event->getFeature()->getDescription(),
                        'scenario' => $event->getScenario()->getTitle(),
                        'line' => $event->getScenario()->getLine(),
                    ];

                    /** @var Behat\Gherkin\Node\StepNode $step */
                    foreach ($event->getScenario()->getSteps() as $step) {
                        $payload['steps'][] = $step->getKeyword() . ' ' . $step->getText();
                    }

                    // Attach screenshots.
                    if (!empty($details['screenshotService'])) {
                        $screenshotService = $details['screenshotService'];
                        $files = $screenshotService->getImages();
                        if (!empty($files)) {
                            array_reverse($files);
                        }
                        $payload['screenshots'] = $files;
                    }

                    $this->failedScenarios[] = $payload['feature'];
                }
                break;
        }

        $this->post($payload);
    }

}
