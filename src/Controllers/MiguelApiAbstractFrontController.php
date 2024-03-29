<?php

namespace Miguel\Controllers;

use Miguel\Utils\MiguelApiError;
use Miguel\Utils\MiguelApiResponse;

abstract class MiguelApiAbstractFrontController extends \ModuleFrontController
{
    /** @var \Miguel */
    public $module;

    protected $methods = ['GET'];

    public function __construct()
    {
        parent::__construct();

        $this->ajax = true;
    }

    public function displayAjax()
    {
        if (!in_array($_SERVER['REQUEST_METHOD'], $this->methods)) {
            return $this->ajaxRender(json_encode(MiguelApiResponse::error(new MiguelApiError('method.not_allowed', 'Method not allowed', 405))));
        }

        if ($this->validateRequest() != true) {
            return $this->returnJsonResponse(MiguelApiResponse::error(MiguelApiError::unauthorized()));
        }

        return $this->returnJsonResponse($this->getResponse());
    }

    protected function validateRequest()
    {
        $valid = $this->module->validateApiAccess();
        if ($valid !== true) {
            return $this->returnJsonResponse($valid);
        }

        return true;
    }

    /**
     * @param MiguelApiResponse $content
     */
    private function returnJsonResponse($content)
    {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($content->getStatus());

        return $this->ajaxRender(json_encode($content));
    }

    abstract protected function getResponse();
}
