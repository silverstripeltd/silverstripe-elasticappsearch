<?php

namespace SilverStripe\ElasticAppSearch\Gateway;

use Elastic\EnterpriseSearch\AbstractEndpoints;
use Elastic\EnterpriseSearch\AppSearch;
use Elastic\EnterpriseSearch\Client;
use Elastic\EnterpriseSearch\EnterpriseSearch;
use Exception;
use SilverStripe\Core\Environment;

trait ConnectionTrait
{
    /**
     * Collectoin of endpoint such as the App Search instance endpoint.
     */
    protected array $endpoints = [];

    /**
     * The Elastic client, set the first time the Client is required and used subsequently.
     */
    protected ?Client $client = null;

    /**
     * Get the App Search instance endpoints.
     *
     * @throws Exception
     */
    protected function getAppSearch(): AppSearch\Endpoints
    {
        return $this->getEndpoint('appSearch');
    }

    /**
     * Get the Enterprise Search instance endpoints.
     *
     * @throws Exception
     */
    protected function getEnterpriseSearch(): EnterpriseSearch\Endpoints
    {
        return $this->getEndpoint('enterpriseSearch');
    }

    /**
     * Helper method to return an endpoint and store the instance of if in the endpoints collection.
     *
     * @throws Exception
     */
    protected function getEndpoint(string $name = 'appSearch'): AbstractEndpoints
    {
        if (!array_key_exists($name, $this->endpoints)) {
            $this->endpoints[$name] = $this->getClient()->{$name}();
        }

        return $this->endpoints[$name];
    }

    /**
     * @throws Exception If either the API endpoint or key aren't defined
     */
    protected function getClient(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        // Ensure the API key and endpoint exist - Support new & old environment variable names
        $apiKey = Environment::hasEnv('ENTERPRISE_SEARCH_API_SEARCH_KEY')
            ? Environment::getEnv('ENTERPRISE_SEARCH_API_SEARCH_KEY')
            : Environment::getEnv('APP_SEARCH_API_SEARCH_KEY');
        $apiEndpoint = Environment::hasEnv('ENTERPRISE_SEARCH_ENDPOINT')
            ? Environment::getEnv('ENTERPRISE_SEARCH_ENDPOINT')
            : Environment::getEnv('APP_SEARCH_ENDPOINT');

        if (!$apiKey || !$apiEndpoint) {
            $msg = 'Missing environment configuration for either ENTERPRISE_SEARCH_API_SEARCH_KEY or ENTERPRISE_SEARCH_ENDPOINT,'
                . ' please read the module documentation.';

            throw new Exception($msg);
        }

        $this->client = new Client([
            'host' => $apiEndpoint,
            'app-search' => [
                'username' => 'elastic',
                'apiKey' => $apiKey,
            ],
            'enterprise-search' => [
                'username' => 'elastic',
                'apiKey' => $apiKey,
            ],
        ]);

        return $this->client;
    }
}
