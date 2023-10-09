<?php

namespace SilverStripe\ElasticAppSearch\Gateway;

use Elastic\Elasticsearch\ClientBuilder;
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

            $this->client = ClientBuilder::create()
                ->setElasticCloudId($elasticCloudId)
                ->setApiKey($apiKey, $apiKeyId)
                ->build();
        }

        return $this->client;
    }
}
