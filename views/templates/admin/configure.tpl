{*
* 2007-2022 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2022 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}
{if $alert_state eq 'info_setup_module'}
<div class="alert alert-info">{l s='Modul Miguel je správně nainstalován a nyní čeká na vaše nastavení.' d='Modules.Miguel.Admin'}</div>
{elseif $alert_state eq 'info_setup_module_first'}
<div class="alert alert-warning">{l s='Pro povolení Miguela je nutné vložit API klíč.' d='Modules.Miguel.Admin'}</div>
{elseif $alert_state eq 'info_setup_module_activate'}
<div class="alert alert-warning">{l s='Miguel není aktivní, povolte jej.' d='Modules.Miguel.Admin'}</div>
{elseif $alert_state eq 'warning_api_fail'}
<div class="alert alert-danger">{l s='Vložené údaje nejsou správné, Miguel není spuštěn.' d='Modules.Miguel.Admin'}</div>
{elseif $alert_state eq 'success_api_ok'}
<div class="alert alert-success">{l s='Miguel je aktivní.' d='Modules.Miguel.Admin'}</div>
{else}
<div class="alert alert-danger">{l s='Nespecifikovaný stav modulu' d='Modules.Miguel.Admin'}</div>
{/if}


<div class="panel">
	<h3><i class="icon icon-book"></i> {l s='Miguel' d='Modules.Miguel.Admin'}</h3>
	<p>
		<strong>{l s='Prodávejte výhodně své e-knihy na vlastním e-shopu!' d='Modules.Miguel.Admin'}</strong><br />
		{l s=' Miguel vám umožní prodávat zabezpečené e-knihy napřímo zákazníkům vašeho e-shopu.' d='Modules.Miguel.Admin'}<br />
		{l s='Zákazník si e-knihu koupí ve vašem e-shopu stejně jako jakoukoli jinou knihu. Zaplacená e-kniha mu však dorazí ze serveru Miguela. Zákazník si ji pak přečte v libovolné aplikaci či zařízení.' d='Modules.Miguel.Admin'}<br />
		{l s='Každá kopie je unikátní pro daného zákazníka a jeho údaje jsou v ní uloženy.' d='Modules.Miguel.Admin'}<br />
	</p>
	<br />
	<p>
		<a href='https://www.melvil.cz/wp-content/uploads/2021/10/servantes-Miguel.pdf' target='_blank'>{l s='Přečtětě si více o Miguelovi.' d='Modules.Miguel.Admin'}</a>
	</p>
</div>

<div class="panel">
	<h3><i class="icon icon-tags"></i> {l s='Dokumentace' d='Modules.Miguel.Admin'}</h3>
	<p>
		&raquo; {l s='Prozkoumejte následující informace pro nastavení modulu:' d='Modules.Miguel.Admin'} :
		<ul>
			<li><a href="https://miguel-test.servantes.cz/v1/swaggerui/documentation" target="_blank">{l s='Dokumentace' d='Modules.Miguel.Admin'}</a></li>
			<li><a href="https://app.servantes.cz/miguel/settings" target="_blank">{l s='Získat API klíč pro server - Produkce' d='Modules.Miguel.Admin'}</a></li>
			<li><a href="https://staging-env.servantes.cz/miguel/settings" target="_blank">{l s='Získat API klíč pro server - Staging' d='Modules.Miguel.Admin'}</a></li>
			<li><a href="https://columbo-test.neatech.cz/miguel/settings" target="_blank">{l s='Získat API klíč pro server - Test' d='Modules.Miguel.Admin'}</a></li>
		</ul>
	</p>
</div>
