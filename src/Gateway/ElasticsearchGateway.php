<?php

namespace SilverStripe\ElasticAppSearch\Gateway;

use Elasticsearch\ClientBuilder as ClientBuilderV7;
use Elastic\Elasticsearch\ClientBuilder as ClientBuilderV8;
use Elasticsearch\Client;
use SilverStripe\Core\Environment;

class ElasticsearchGateway
{
    /**
     * @var Client
     */
    protected $client;

    public function search(array $params)
    {
        $client = $this->getClient();
        return $client->search($params);
    }

    protected function getClient()
    {
        if (!$this->client) {
            $elasticCloudId = Environment::getEnv('ELASTICSEARCH_CLOUD_ID');
            $apiKeyId = Environment::getEnv('ELASTICSEARCH_API_KEY_ID');
            $apiKey = Environment::getEnv('ELASTICSEARCH_API_KEY');

            // Handle differences between v7 and v8 of the elastic php client module:
            // - Different namespaces
            // - Params for setApiKey reversed
            if (class_exists('Elastic\Elasticsearch\ClientBuilder')) {
                $this->client = ClientBuilderV8::create()
                    ->setElasticCloudId($elasticCloudId)
                    ->setApiKey($apiKey, $apiKeyId)
                    ->build();
            } else {
                $this->client = ClientBuilderV7::create()
                    ->setElasticCloudId($elasticCloudId)
                    ->setApiKey($apiKeyId, $apiKey)
                    ->build();
            }
        }

        return $this->client;
    }
}
