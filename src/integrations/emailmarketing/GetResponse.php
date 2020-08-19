<?php
namespace verbb\formie\integrations\emailmarketing;

use verbb\formie\base\Integration;
use verbb\formie\base\EmailMarketing;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\EmailMarketingField;
use verbb\formie\models\EmailMarketingList;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;

class GetResponse extends EmailMarketing
{
    // Properties
    // =========================================================================

    public $handle = 'getResponse';


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function getName(): string
    {
        return Craft::t('formie', 'GetResponse');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'Sign up users to your GetResponse lists to grow your audience for campaigns.');
    }

    /**
     * @inheritDoc
     */
    public function beforeSave(): bool
    {
        if ($this->enabled) {
            $apiKey = $this->settings['apiKey'] ?? '';

            if (!$apiKey) {
                $this->addError('apiKey', Craft::t('formie', 'API key is required.'));
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchLists()
    {
        $allLists = [];
            $lists = $this->_request('GET', 'campaigns');

            foreach ($lists as $list) {
                // While we're at it, fetch the fields for the list
                $fields = $this->_request('GET', 'custom-fields');

                $listFields = [
                    new EmailMarketingField([
                        'tag' => 'email',
                        'name' => Craft::t('formie', 'Email'),
                        'type' => 'email',
                        'required' => true,
                    ]),
                    new EmailMarketingField([
                        'tag' => 'name',
                        'name' => Craft::t('formie', 'Name'),
                        'type' => 'name',
                    ]),
                ];
            
                foreach ($fields as $field) {
                    $listFields[] = new EmailMarketingField([
                        'tag' => $field['customFieldId'],
                        'name' => $field['name'],
                        'type' => $field['fieldType'],
                    ]);
                }

                $allLists[] = new EmailMarketingList([
                    'id' => $list['campaignId'],
                    'name' => $list['name'],
                    'fields' => $listFields,
                ]);
            }
        try {
            
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }

        return $allLists;
    }

    /**
     * @inheritDoc
     */
    public function sendPayload(Submission $submission): bool
    {
        try {
            $fieldValues = $this->getFieldMappingValues($submission);

            // Pull out email, as it needs to be top level
            $email = ArrayHelper::remove($fieldValues, 'email');
            $name = ArrayHelper::remove($fieldValues, 'name');

            // Format custom fields
            $customFields = [];

            foreach ($fieldValues as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $customFields[] = [
                            'customFieldId' => $key,
                            'value' => [$v],
                        ];
                    }
                } else {
                    $customFields[] = [
                        'customFieldId' => $key,
                        'value' => [$value],
                    ];
                }
            }

            $payload = [
                'email' => $email,
                'name' => $name,
                'campaign' => [
                    'campaignId' => $this->listId,
                ],
                'customFieldValues' => $customFields,
            ];

            // Allow events to cancel sending
            if (!$this->beforeSendPayload($submission, $payload)) {
                return false;
            }

            // Add or update
            $response = $this->_request('POST', 'contacts', [
                'json' => $payload,
            ]);

            // Allow events to say the response is invalid
            if (!$this->afterSendPayload($submission, $payload, $response)) {
                return false;
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $response = $this->_request('GET', 'accounts');
            $accountId = $response['accountId'] ?? '';

            if (!$accountId) {
                Integration::error($this, 'Unable to find “{accountId}” in response.', true);
                return false;
            }

        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Private Methods
    // =========================================================================

    private function _getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        $apiKey = $this->settings['apiKey'] ?? '';

        if (!$apiKey) {
            Integration::error($this, 'Invalid API Key for GetResponse', true);
        }

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => 'https://api.getresponse.com/v3/',
            'headers' => ['X-Auth-Token' => 'api-key ' . $apiKey],
        ]);
    }

    private function _request(string $method, string $uri, array $options = [])
    {
        $response = $this->_getClient()->request($method, trim($uri, '/'), $options);

        return Json::decode((string)$response->getBody());
    }
}