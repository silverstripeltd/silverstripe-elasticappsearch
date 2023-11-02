<?php

namespace SilverStripe\ElasticAppSearch\Gateway;

use Elasticsearch\ClientBuilder as ClientBuilderLegacy;
use Elastic\Elasticsearch\ClientBuilder as ClientBuilder;
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

            // Handle namespace and setApiKey changes made in Version 8 of the elastic php client module:
            if (class_exists('Elastic\Elasticsearch\ClientBuilder')) {
                $this->client = ClientBuilder::create()
                    ->setElasticCloudId($elasticCloudId)
                    ->setApiKey($apiKey, $apiKeyId)
                    ->build();
            } else {
                $this->client = ClientBuilderLegacy::create()
                    ->setElasticCloudId($elasticCloudId)
                    ->setApiKey($apiKeyId, $apiKey)
                    ->build();
            }
        }

        return $this->client;
    }
}
