{**
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
*}

{if $alert_state eq 'info_setup_module'}
<div class="alert alert-info">{l s='The Miguel add-on has been installed correctly and is now waiting for your settings.' mod='miguel'}</div>
{elseif $alert_state eq 'info_setup_module_first'}
<div class="alert alert-warning">{l s='An API key must be inserted to enable Miguel.' mod='miguel'}</div>
{elseif $alert_state eq 'info_setup_module_activate'}
<div class="alert alert-warning">{l s='Miguel is not active, enable it.' mod='miguel'}</div>
{elseif $alert_state eq 'warning_api_fail'}
<div class="alert alert-danger">{l s='Your API key is incorrect, Miguel is not running.' mod='miguel'}</div>
{elseif $alert_state eq 'success_api_ok'}
<div class="alert alert-success">{l s='Miguel is running correctly' mod='miguel'}</div>
{else}
<div class="alert alert-danger">{l s='Unspecified add-on status' mod='miguel'}</div>
{/if}


<div class="panel">
	<h3><i class="icon icon-book"></i> {l s='Miguel' mod='miguel'}</h3>
	<p>
		<strong>{l s='Sell your e-books on your own e-shop!' mod='miguel'}</strong><br />
		{l s='Miguel add-on allows you to sell secure e-books directly to customers from your e-shop.' mod='miguel'}<br />
		{l s='A customer buys an e-book in your e-shop just like any other book. However, the paid e-book gets from Miguel\'s server. The customer then can read it in a favorite application or device.' mod='miguel'}<br />
		{l s='Each copy is unique for a given customer and cutomer data is stored in it.' mod='miguel'}<br />
	</p>
	<br />
	<p>
		<a href='https://www.servantes.cz/miguel' target='_blank'>{l s='Read more about Miguel.' mod='miguel'}</a>
	</p>
</div>

<div class="panel">
	<h3><i class="icon icon-tags"></i> {l s='Documentation' mod='miguel'}</h3>
	<p>
		&raquo; {l s='Explore the following information to set up the module' mod='miguel'} :
		<ul>
			<li><a href="https://docs.miguel.servantes.cz" target="_blank">{l s='Documentation' mod='miguel'}</a></li>
			<li><a href="https://app.servantes.cz/miguel/settings" target="_blank">{l s='Get API key' mod='miguel'}</a></li>
		</ul>
	</p>
</div>
