<?php

namespace HZ\Illuminate\Mongez\Http;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

abstract class AdminUIController
{
    /**
     * Repository object
     * 
     * @var mixed
     */
    protected $repository;

    /**
     * Controller repository
     *
     * @var mixed
     */
    protected $controllerInfo = [
        'repository' => '',
        'view' => '',
        'listOptions' => [
            'select' => [],
            'paginate' => null
        ],
        'returnOn' => [
            'store' => 'single-record',
            'update' => 'single-record',
        ],
        'rules' => [
            'all' => [],
            'store' => [],
            'update' => [],
        ],
    ];

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        parent::__construct();

        if (!empty($this->controllerInfo['repository'])) {
            $this->repository = repo($this->controllerInfo['repository']);
        }
    }

    /**
     * Get List of records
     *
     * @param   \Illuminate\Http\Request $request
     * @return string
     */
    public function index(Request $request)
    {
        $data['records'] = $this->repository->list($this->listOptions($request));

        if ($this->repository->getPaginateInfo()) {
            $data['paginationInfo'] = $this->repository->getPaginateInfo();
        }

        return $this->render('index', $data);
    }

    /**
     * Render the given view path
     * 
     * @param string $viewPath
     * @param  array $data
     * @return string
     */
    protected function render(string $viewPath, array $data = [])
    {
        return view($this->controllerInfo['view-path'] . '.' . $viewPath, $data);
    }

    /**
     * Get  options
     *
     * @param  \Illuminate\Http\Request $request
     * @return array
     */
    protected function listOptions(Request $request): array
    {
        return array_merge($request->All(), $this->controllerInfo('listOptions'), ['as-model' => true]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $id = (int) $id;

        if (!$this->repository->has($id)) {
            return $this->notFound();
        }

        $data['record'] = $this->repository->getModel($id);

        return $this->render('index', $data);
    }

    /**
     * Get value from controller info
     * 
     * @param  string $key 
     * @return mixed
     */
    protected function controllerInfo(string $key)
    {
        return Arr::get($this->controllerInfo, $key);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param   \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $rules = array_merge((array) $this->controllerInfo('rules.all'), $this->storeValidation($request));

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->badRequest($validator->errors());
        }

        $model = $this->repository->create($request);

        $returnOnStore = $this->controllerInfo['returnOn']['store'] ?? config('mongez.admin.returnOn.store', 'single-record');

        if ($returnOnStore == 'single-record') {
            return $this->show($model->id, $request);
        } elseif ($returnOnStore == 'all-records') {
            return $this->index($request);
        } else {
            return redirect()->back();
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, Request $request)
    {
        if ($this->repository->deleteHasDependence()) {
            $deletingValidationErrors = $this->validateBeforeDeleting($this->repository->getDeleteDependencies(), $id);
            if (!empty($deletingValidationErrors)) {
                return $this->badRequest($deletingValidationErrors);
            }
        }

        $this->repository->delete((int) $id);

        return $request->isAjax() ? $this->success() : redirect()->back();
    }

    /**
     * Validate if this model has depended on another these tables .
     *
     * @param  array $deleteDependenceTables
     * @param  int   $modelId
     * @return array $errors
     */
    public function validateBeforeDeleting($deleteDependenceTables, $modelId): array
    {
        $errors = [];

        $isUsingSoftDelete = $this->repository->isUsingSoftDelete();

        foreach ($deleteDependenceTables as $table) {
            $rules = Rule::unique($table['tableName'], $table['key']);

            if ($isUsingSoftDelete) {
                $validationRules = $rules->where(function ($query) {

                    $query->whereNull('deleted_at');
                });
            }

            $validationRules['data'][$table['tableName'] . '_id'] = (int)$modelId;

            $validationRules['rules'][$table['tableName'] . '_id'] = $rules;

            $validationRules['messages']['unique.' . $table['tableName'] . '_id'] = $table['message'];
        }

        $validator = Validator::make($validationRules['data'], $validationRules['rules'], $validationRules['messages']);

        if ($validator->fails()) {
            $errors[] = $validator->errors();
        }

        return $errors;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param   \Illuminate\Http\Request  $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!$this->repository->has($id)) {
            return $this->notFound();
        }

        $rules = array_merge((array) $this->controllerInfo('rules.all'), $this->updateValidation($id, $request));

        foreach ($rules as &$rulesList) {
            if (!is_array($rulesList)) {
                $rulesList = explode('|', $rulesList);
            }

            foreach ($rulesList as &$rule) {
                if ($rule == 'unique') {
                    if (!Str::contains($rule, ':')) {
                        $rule = Rule::unique($this->repository->getTableName())->ignore((int) $id, 'id');
                    }
                }
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->badRequest($validator->errors());
        }

        $this->repository->update($id, $request);

        $returnOnUpdate = $this->controllerInfo['returnOn']['update'] ?? config('mongez.admin.returnOn.update', 'single-record');

        if ($returnOnUpdate == 'single-record') {
            return $this->show($id, $request);
        } elseif ($returnOnUpdate == 'all-records') {
            return $this->index($request);
        } else {
            return $this->success();
        }
    }

    /**
     * Make custom validation for update
     *
     * @param  int $id
     * @param   \Illuminate\Http\Request $request
     * @return array
     */
    protected function updateValidation($id, Request $request): array
    {
        return (array) $this->controllerInfo('rules.update');
    }

    /**
     * Make custom validation for store
     *
     * @param int $id
     * @param mixed $request
     * @return array
     */
    protected function storeValidation($request): array
    {
        return (array) $this->controllerInfo('rules.store');
    }
}
