<?php

namespace Madmatt\ElasticAppSearch\Gateway;

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
     * @throws Exception If either the API endpoint or key aren't defined
     * @return Client
     */
    protected function getClient()
    {
        if (!isset($this->client) || !$this->client instanceof Client) {
            // Ensure the API key and endpoint exist
            $apiKey = Environment::getEnv('APP_SEARCH_API_SEARCH_KEY');
            $apiEndpoint = Environment::getEnv('APP_SEARCH_ENDPOINT');

            if (!$apiKey || !$apiEndpoint) {
                $msg = 'Missing environment configuration for either APP_SEARCH_API_SEARCH_KEY or APP_SEARCH_ENDPOINT,'
                    . ' please read the module documentation.';

                throw new Exception($msg);
            }

            $builder = ClientBuilder::create($apiEndpoint, $apiKey);
            $this->client = $builder->build();
        }

        return $this->client;
    }
}
