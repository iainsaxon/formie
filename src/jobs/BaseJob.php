<?php
namespace verbb\formie\jobs;

use verbb\formie\Formie;

use Craft;
use craft\base\Element;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\queue\BaseJob as CraftBaseJob;

abstract class BaseJob extends CraftBaseJob
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function updatePayload($event)
    {
        // When an error occurs on the job, we want to update the Job Data for the job. This helps immensly with
        // debugging, and provides the customer with context on exactly _what_ is trying to be sent.
        // We have to do a direct database update however, because the Job Data is only serialized when the job 
        // is created. The payload is changed via multiple calls in the task, so we want to reflect that,

        // Ensure that the payload is simplified a little. For some instances `serialize()` can't handle Closures
        // and sometimes the payload is a Craft element, which contains them (potentially).
        if (property_exists($event->job, 'payload') && $event->job->payload instanceof Element) {
            $payload = Json::decode(Json::encode($event->job->payload));

            // Add in custom fields with a bit more context
            foreach ($event->job->payload->getFieldLayout()->getFields() as $field) {
                $payload['fields'][] = [
                    'type' => get_class($field),
                    'handle' => $field->handle,
                    'value' => $event->job->payload->getFieldValue($field->handle),
                ];
            }

            $event->job->payload = $payload;
        }

        $jobData = $this->_jobData($event->job);

        try {
            // Serialize it again ready to save
            $message = Craft::$app->getQueue()->serializer->serialize($jobData);

            Db::update(Table::QUEUE, ['job' => $message], ['id' => $event->id], [], false);
        } catch (\Throwable $e) {
            Formie::error(Craft::t('formie', 'Unable to update job info debug: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }
    }


    // Private Methods
    // =========================================================================

    /**
     * Checks if $job is a resource and if so, convert it to a serialized format.
     *
     * @param string|resource $job
     * @return string
     */
    private function _jobData($job)
    {
        if (is_resource($job)) {
            $job = stream_get_contents($job);

            if (is_string($job) && strpos($job, 'x') === 0) {
                $hex = substr($job, 1);
                if (StringHelper::isHexadecimal($hex)) {
                    $job = hex2bin($hex);
                }
            }
        }

        return $job;
    }
}
