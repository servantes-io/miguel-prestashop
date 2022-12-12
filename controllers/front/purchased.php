<?php
/**
 * 2007-2020 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */


//include_once('../../../../config/config.inc.php');
//include_once('../../miguel.php');




class MiguelPurchasedModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool If set to true, will be redirected to authentication page
     */
    public $auth = true;

    public function initContent()
    {
        parent::initContent();

        $this->logger = new FileLogger(0); //0 == debug level, logDebug() won’t work without this.
        $this->logger->setFilename(_PS_ROOT_DIR_."/modules/miguel/debug.log");

        $module = new Miguel();
        $books = $module->getOrderedBooks();
        
        $this->context->smarty->assign(
            [
                'miguelTitlePage' => Configuration::get('miguel_PurchasedPageName', $this->context->language->id),
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
          'title' => Configuration::get('miguel_PurchasedPageName', $this->context->language->id),
          'url' => $this->context->link->getModuleLink('miguel', 'purchased'),
        ];

        return $breadcrumb;
    }
}
