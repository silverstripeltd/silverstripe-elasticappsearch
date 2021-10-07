<?php

namespace SilverStripe\ElasticAppSearch\Controller;

use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ElasticAppSearch\Gateway\AppSearchGateway;
use SilverStripe\ElasticAppSearch\Service\AppSearchService;
use stdClass;

class ClickthroughController extends Controller
{
    private static $url_handlers = [
        '_click' => 'index'
    ];

    private static $allowed_actions = [
        'index'
    ];

    private static $base_url = '_click';

    public function index(HTTPRequest $request)
    {
        /** @var AppSearchService $service */
        $service = Injector::inst()->get(AppSearchService::class);

        // Get the base64 encoded string that we want to look at
        $data = base64_decode($request->getVar('d'));

        if ($data === false || strlen($data) <= 0) {
            $this->httpError(404);
        }

        $data = json_decode($data);

        if ($data === null) {
            $this->httpError(404);
        }

        // Confirm all necessary data to reconstruct the DataObject exists
        if (!isset($data->id) || !isset($data->type)) {
            $this->httpError(404);
        }

        // Try to map the type back to the root class name and therefore the DataObject
        $class = $service->typeToClass($data->type);

        try {
            if (!$class) {
                throw new LogicException(sprintf('Invalid type "%s" passed to ClickthroughController', $data->type));
            }

            $obj = $class::get()->byID($data->id);

            if ($obj && $obj->exists()) {
                $this->registerClickthrough($data); // Attempt to register a clickthrough, swallow any errors
                $link = $obj->Link();
                return $this->redirect($obj->Link(), 302); // Force temporary redirect
            }
        } catch (Exception $e) {
            // If we can't find the object, then we can't link to the page as it no longer exists
            $err = sprintf(
                'Couldn\'t register clickthrough. Error: "%s"; Data provided: %s',
                $e->getMessage(),
                var_export($data, true)
            );

            Injector::inst()->get(LoggerInterface::class)->error($err);
            $this->httpError(404);
        }

        // If we get here, then we weren't able to resolve the given data to a specific page that the user can be
        // redirected to, so our final fallback is to show the 404 page
        $this->httpError(404);
    }

    /**
     * Register a clickthrough with Elastic, provided enough information is passed in order to do so.
     *
     * @param stdClass $data
     */
    protected function registerClickthrough(stdClass $data): void
    {
        // Now that we have the object, we can try and register a clickthrough in Elastic for it
        // We need at least `engineName`, `query`, `documentId` to come through the array to register in Elastic
        if (!isset($data->engineName) || !isset($data->query) || !isset($data->documentId)) {
            $err = sprintf(
                'Not enough information passed to ClickthroughController::registerClickthrough(). Passed data: %s',
                var_export($data, true)
            );

            Injector::inst()->get(LoggerInterface::class)->warning($err);
            return;
        }

        /** @var AppSearchGateway $gateway */
        $gateway = Injector::inst()->get(AppSearchGateway::class);
        $requestId = isset($data->requestId) ? $data->requestId : null;
        $tags = (isset($data->tags) && sizeof($data->tags) > 0) ? $data->tags : null; // An empty array breaks Elastic

        try {
            $gateway->logClickthrough($data->engineName, $data->query, $data->documentId, $requestId, $tags);
        } catch (Exception $e) {
            // If we get an error, log it but swallow the error, logging a clickthrough isn't important
            // enough to interrupt the user
            $err = sprintf(
                'Error while logging clickthrough in Elastic App Search: %s. Traceback:',
                $e->getMessage(),
                $e->getTraceAsString()
            );

            Injector::inst()->get(LoggerInterface::class)->warning($err);
        }
    }

    public static function get_base_url()
    {
        return self::config()->base_url;
    }
}
