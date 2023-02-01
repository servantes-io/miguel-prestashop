<?php
/**
* 2023 Servantes
*
* This file is licenced under the Software License Agreement.
* With the purchase or the installation of the software in your application
* you accept the licence agreement.
*
* You must not modify, adapt or create derivative works of this source code
*
*  @author Pavel Vejnar <vejnar.p@gmail.com>
*  @copyright  2022 - 2023 Servantes
*  @license LICENSE.txt
*/
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
