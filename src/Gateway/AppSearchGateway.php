<?php

namespace SilverStripe\ElasticAppSearch\Gateway;

use Elastic\AppSearch\Client\Client;
use Elastic\AppSearch\Client\ClientBuilder;
use Exception;
use SilverStripe\Core\Environment;

class AppSearchGateway
{
    /**
     * @var Client The Elastic client, set the first time the Client is required and used subsequently.
     */
    protected $client;

    /**
     * Performs a search of the provided engine with the given query string and (optional) search parameters using the
     * Elastic App Search client.
     *
     * @param string $engineName
     * @param string $query
     * @param array $searchParams
     * @return array
     */
    public function search(string $engineName, string $query, array $searchParams = [])
    {
        $client = $this->getClient();
        return $client->search($engineName, $query, $searchParams);
    }

    /**
     * Performs a multisearch of the provided engine with the given queries using the Elastic App Search client.
     *
     * @param string $engineName
     * @param array $queries
     * @return array
     */
    public function multisearch(string $engineName, array $queries)
    {
        $client = $this->getClient();
        return $client->multiSearch($engineName, $queries);
    }

    public function logClickthrough(string $engineName, string $query, string $documentId, ?string $requestId = null, ?array $tags = null)
    {
        $client = $this->getClient();
        return $client->logClickthrough($engineName, $query, $documentId, $requestId, $tags);
    }

    /**
     * @throws Exception If either the API endpoint or key aren't defined
     * @return Client
     */
    protected function getClient()
    {
        if (!isset($this->client) || !$this->client instanceof Client) {
            // Ensure the API key and endpoint exist - Support new & old environment variable names
            $apiKey = Environment::getEnv('ENTERPRISE_SEARCH_API_SEARCH_KEY')
                ? Environment::getEnv('ENTERPRISE_SEARCH_API_SEARCH_KEY')
                : Environment::getEnv('APP_SEARCH_API_SEARCH_KEY');
            $apiEndpoint = Environment::getEnv('ENTERPRISE_SEARCH_ENDPOINT')
                ? Environment::getEnv('ENTERPRISE_SEARCH_ENDPOINT')
                : Environment::getEnv('APP_SEARCH_ENDPOINT');

            if (!$apiKey || !$apiEndpoint) {
                $msg = 'Missing environment configuration for either '
                    . 'ENTERPRISE_SEARCH_API_SEARCH_KEY|APP_SEARCH_API_SEARCH_KEY or '
                    . 'ENTERPRISE_SEARCH_ENDPOINT|APP_SEARCH_ENDPOINT,'
                    . ' please read the module documentation.';

                throw new Exception($msg);
            }

            $builder = ClientBuilder::create($apiEndpoint, $apiKey);
            $this->client = $builder->build();
        }

        return $this->client;
    }
}
