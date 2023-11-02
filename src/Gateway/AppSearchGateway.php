<?php

namespace SilverStripe\ElasticAppSearch\Gateway;

use Elastic\EnterpriseSearch\AppSearch\Request;
use Elastic\EnterpriseSearch\AppSearch\Schema;
use Elastic\EnterpriseSearch\Response\Response;
use SilverStripe\Core\Injector\Injectable;

class AppSearchGateway
{
    use ConnectionTrait;
    use Injectable;

    /**
     * Performs a search of the provided engine with the given query string and (optional) search parameters using the
     * Elastic App Search client.
     */
    public function search(string $engineName, Schema\SearchRequestParams $query): Response
    {
        return $this->getAppSearch()->search(new Request\Search($engineName, $query));
    }

    /**
     * Performs a multisearch of the provided engine with the given queries using the Elastic App Search client.
     */
    public function multisearch(string $engineName, Schema\MultiSearchData $queries): Response
    {
        return $this->getAppSearch()->multiSearch(new Request\MultiSearch($engineName, $queries));
    }

    public function querySuggestion(string $engineName, Schema\QuerySuggestionRequest $request): Response
    {
        return $this->getAppSearch()->querySuggestion(new Request\QuerySuggestion($engineName, $request));
    }

    public function logClickthrough(string $engineName, string $query, string $documentId, ?string $requestId = null, ?array $tags = null): Response
    {
        $params = new Schema\ClickParams($query, $documentId);

        if ($requestId) {
            $params->request_id = $requestId;
        }

        if (is_array($tags)) {
            $params->tags = $tags;
        }

        return $this->getAppSearch()->logClickthrough(new Request\LogClickthrough($engineName, $params));
    }
}
