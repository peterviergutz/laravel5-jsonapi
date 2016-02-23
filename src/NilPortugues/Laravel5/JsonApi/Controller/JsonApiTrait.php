<?php
/**
 * Author: Nil Portugués Calderó <contact@nilportugues.com>
 * Date: 13/01/16
 * Time: 19:56.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NilPortugues\Laravel5\JsonApi\Controller;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use NilPortugues\Api\JsonApi\Http\Factory\RequestFactory;
use NilPortugues\Api\JsonApi\Http\Response\ResourceNotFound;
use NilPortugues\Api\JsonApi\Server\Actions\CreateResource;
use NilPortugues\Api\JsonApi\Server\Actions\DeleteResource;
use NilPortugues\Api\JsonApi\Server\Actions\GetResource;
use NilPortugues\Api\JsonApi\Server\Actions\ListResource;
use NilPortugues\Api\JsonApi\Server\Actions\PatchResource;
use NilPortugues\Api\JsonApi\Server\Actions\PutResource;
use NilPortugues\Api\JsonApi\Server\Errors\Error;
use NilPortugues\Api\JsonApi\Server\Errors\ErrorBag;
use NilPortugues\Laravel5\JsonApi\Eloquent\EloquentHelper;
use NilPortugues\Laravel5\JsonApi\JsonApiSerializer;
use Symfony\Component\HttpFoundation\Response;

trait JsonApiTrait
{
    /**
     * @var JsonApiSerializer
     */
    protected $serializer;

    /**
     * @var int
     */
    protected $pageSize = 10;

    /**
     * @param JsonApiSerializer $serializer
     */
    public function __construct(JsonApiSerializer $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * Get many resources.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index()
    {
        $apiRequest = RequestFactory::create();

        $page = $apiRequest->getPage();
        if (!$page->size()) {
            $page->setSize($this->pageSize);
        }

        $fields = $apiRequest->getFields();
        $sorting = $apiRequest->getSort();
        $included = $apiRequest->getIncludedRelationships();
        $filters = $apiRequest->getFilters();

        $resource = new ListResource($this->serializer, $page, $fields, $sorting, $included, $filters);

        $totalAmount = $this->totalAmountResourceCallable();
        $results = $this->listResourceCallable();

        $controllerAction = '\\'.get_called_class().'@index';
        $uri = action($controllerAction, []);

        return $this->addHeaders($resource->get($totalAmount, $results, $uri, get_class($this->getDataModel())));
    }

    /**
     * Returns the total number of results available for the current resource.
     *
     * @return callable
     * @codeCoverageIgnore
     */
    protected function totalAmountResourceCallable()
    {
        return function () {
            $idKey = $this->getDataModel()->getKeyName();

            return $this->getDataModel()->query()->get([$idKey])->count();
        };
    }

    /**
     * Returns an Eloquent Model.
     *
     * @return Model
     */
    abstract public function getDataModel();

    /**
     * Returns a list of resources based on pagination criteria.
     *
     * @return callable
     * @codeCoverageIgnore
     */
    protected function listResourceCallable()
    {
        return function () {
            return EloquentHelper::paginate($this->serializer, $this->getDataModel()->query())->get();
        };
    }

    /**
     * @param Response $response
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function addHeaders(Response $response)
    {
        return $response;
    }

    /**
     * @param Request $request
     *
     * @return array
     */
    protected function getData(Request $request)
    {
        $input = json_decode($request->getContent(), true);
        return $input['data'];
    }

    /**
     * @return ResourceNotFound
     */
    public function create()
    {
        return new ResourceNotFound();
    }

    /**
     * Get single resource.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function show($id)
    {
        $apiRequest = RequestFactory::create();

        $resource = new GetResource(
            $this->serializer,
            $apiRequest->getFields(),
            $apiRequest->getIncludedRelationships()
        );

        $find = $this->findResourceCallable($id);

        return $this->addHeaders($resource->get($id, get_class($this->getDataModel()), $find));
    }

    /**
     * @param $id
     *
     * @return callable
     * @codeCoverageIgnore
     */
    protected function findResourceCallable($id)
    {
        return function () use ($id) {
            $idKey = $this->getDataModel()->getKeyName();
            $model = $this->getDataModel()->query()->where($idKey, $id)->first();

            return $model;
        };
    }

    /**
     * Post Action.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        $createResource = $this->createResourceCallable();
        $resource = new CreateResource($this->serializer);

        return $this->addHeaders(
            $resource->get($this->getData($request), get_class($this->getDataModel()), $createResource)
        );
    }

    /**
     * Reads the input and creates and saves a new Eloquent Model.
     *
     * @return callable
     * @codeCoverageIgnore
     */
    protected function createResourceCallable()
    {
        return function (array $data, array $values, ErrorBag $errorBag) {
            $model = $this->getDataModel()->newInstance();

            foreach ($values as $attribute => $value) {
                $model->setAttribute($attribute, $value);
            }

            if (!empty($data['id'])) {
                $model->setAttribute($model->getKeyName(), $values['id']);
            }

            try {
                $model->save();
            } catch (\Exception $e) {
                $errorBag[] = new Error('creation_error', 'Resource could not be created');
                throw $e;
            }

            return $model;
        };
    }

    /**
     * @param Request $request
     * @param $id
     *
     * @return Response
     */
    public function update(Request $request, $id)
    {
        return (strtoupper($request->getMethod()) === 'PUT') ? $this->putAction($request,
            $id) : $this->patchAction($request, $id);
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function putAction(Request $request, $id)
    {
        $find = $this->findResourceCallable($id);
        $update = $this->updateResourceCallable();

        $resource = new PutResource($this->serializer);

        return $this->addHeaders(
            $resource->get(
                $id,
                $this->getData($request),
                get_class($this->getDataModel()),
                $find,
                $update
            )
        );
    }

    /**
     * @return callable
     * @codeCoverageIgnore
     */
    protected function updateResourceCallable()
    {
        return function (Model $model, array $values, ErrorBag $errorBag) {
            foreach ($values as $attribute => $value) {
                $model->$attribute = $value;
            }
            try {
                $model->update();
            } catch (\Exception $e) {
                $errorBag[] = new Error('update_failed', 'Could not update resource.');
                throw $e;
            }
        };
    }

    /**
     * @param Request $request
     * @param         $id
     *
     * @return Response
     */
    protected function patchAction(Request $request, $id)
    {
        $find = $this->findResourceCallable($id);
        $update = $this->updateResourceCallable();

        $resource = new PatchResource($this->serializer);

        return $this->addHeaders(
            $resource->get(
                $id,
                $this->getData($request),
                get_class($this->getDataModel()),
                $find,
                $update
            )
        );
    }

    /**
     * @return ResourceNotFound
     */
    public function edit()
    {
        return new ResourceNotFound();
    }

    /**
     * @param $id
     *
     * @return Response
     */
    public function destroy($id)
    {
        $find = $this->findResourceCallable($id);

        $delete = $this->deleteResourceCallable($id);

        $resource = new DeleteResource($this->serializer);

        return $this->addHeaders($resource->get($id, get_class($this->getDataModel()), $find, $delete));
    }

    /**
     * @param $id
     *
     * @return \Closure
     */
    protected function deleteResourceCallable($id)
    {
        return function () use ($id) {
            $idKey = $this->getDataModel()->getKeyName();
            $model = $this->getDataModel()->query()->where($idKey, $id)->first();

            return $model->delete();
        };
    }
}
