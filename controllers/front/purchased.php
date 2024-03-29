<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelPurchasedModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool If set to true, will be redirected to authentication page
     */
    public $auth = true;

    public function initContent()
    {
        parent::initContent();

        $module = new Miguel();
        $books = $module->getOrderedBooks();

        $this->context->smarty->assign(
            [
                'miguelTitlePage' => $this->module->l('Purchased e-books'),
                'miguel_purchased' => $books,
            ]
        );

        $this->setTemplate('module:miguel/views/templates/front/purchased.tpl');
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();

        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->module->l('Purchased e-books'),
            'url' => $this->context->link->getModuleLink('miguel', 'purchased'),
        ];

        return $breadcrumb;
    }
}
