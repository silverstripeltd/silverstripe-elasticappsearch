<?php

namespace Madmatt\ElasticAppSearch\Gateway;

use Exception;
use SilverStripe\Dev\TestOnly;

/**
 * Class FakeAppSearchGateway
 * @package Madmatt\ElasticAppSearch\Gateway
 *
 * This class should not be used in production, it is intended to be used for automated testing purposes.
 *
 * You can inject this into the Service class via Silverstripe dependency injection in your tests:
 *
 * public function setUp() {
 *     $this->gateway = new Madmatt\ElasticAppSearch\Gateway\FakeAppSearchGateway();
 *     Injector::inst()->registerService($this->gateway, Madmatt\ElasticAppSearch\Gateway\AppSearchGateway::class);
 * }
 *
 * public function testSomething() {
 *     $this->gateway->setResponseFromFile('app/tests/fixtures/searchresults.json');
 *
 *     // The service will get constructed with the FakeAppSearchGateway in place, $response will be the JSON above
 *     $s = SilverStripe\Core\Injector\Injector::inst()->get(Madmatt\ElasticAppSearch\Service\AppSearchService::class);
 *     $response = $s->search('query'); // Returns objects based on the data provided in searchresults.json
 */
class FakeAppSearchGateway extends AppSearchGateway implements TestOnly
{
    /**
     * @var string The fake response to return when performing a search on this gateway
     */
    private $response;

    public function search(string $engineName, string $query, array $searchParams = [])
    {
        if (!$this->response) {
            throw new Exception('Response not set in FakeAppSearchGateway');
        }

        return json_decode($this->response, true);
    }

    public function multisearch(string $engineName, array $queries)
    {
        if (!$this->response) {
            throw new Exception('Response not set in FakeAppSearchGateway');
        }

        return json_decode($this->response, true);
    }

    public function setResponseFromFile(string $filename): FakeAppSearchGateway
    {
        $this->setResponse(file_get_contents($filename));
        return $this;
    }

    public function setResponse(string $response): FakeAppSearchGateway
    {
        $this->response = $response;
        return $this;
    }
}
