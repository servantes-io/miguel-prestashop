{**
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
 *}


{extends file='customer/page.tpl'}

{block name='page_title'}
  {$miguelTitlePage}
{/block}

{block name='page_content'}
  <section  id="history" class="page-customer-account">
    <div id="content" class="page-content">
    {if (count($miguel_purchased) > 0) && ('debug'|array_key_exists:$miguel_purchased) == false}
      <h6>{l s='Zde jsou všechny e-knihy, které jste si zakoupili pod tímto zákaznickým účtem.' d='Modules.Miguel.Customer'}</h6>
      <table class="table table-striped table-bordered table-labeled hidden-sm-down">
        <thead class="thead-default">
          <tr>
            <th>{l s='Název knihy' d='Modules.Miguel.Customer'}</th>
            <th>{l s='Označení objednávky' d='Modules.Miguel.Customer'}</th>
            <th>{l s='Datum zakoupení' d='Modules.Miguel.Customer'}</th>
            <th>{l s='Ke stažení' d='Modules.Miguel.Customer'}</th>
            {*<th>&nbsp;</th>*}
          </tr>
        </thead>
        <tbody>
          {foreach from=$miguel_purchased item=book}
            <tr>
              <th scope="row">{$book.product.book.title}</th>
              <td>{$book.reference}</td>
              <td>{$book.date_add}</td>
              <td>
                {if $book.paid}
                  {$product_count = count($book.product.formats)}
                  {if $product_count > 0}
                    {foreach from=$book.product.formats key=k item=format}
                      <a href="{$format.download_url}">{$format.format}</a>{if $product_count-1 != $k}, {/if} 
                    {/foreach}
                  {else}
                    <th>{l s='Knihy se připravují' d='Modules.Miguel.Customer'}</th>
                  {/if}
                {else}
                  {$book.order_state}
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>

      <div class="orders hidden-md-up">
        {foreach from=$miguel_purchased item=book}
          <div class="order">
            <div class="row">
              <div class="col-xs-12">
                <h4>{$book.product.book.title}</h4>
                <div class="">{l s='Označení objednávky' d='Modules.Miguel.Customer'}: {$book.reference}</div>
                <div class="">{l s='Datum zakoupení' d='Modules.Miguel.Customer'}: {$book.date_add}</div>
                <div class="">
                  {if $book.paid}
                    {$product_count = count($book.product.formats)}
                    {if $product_count > 0}
                      {l s='Ke stažení' d='Modules.Miguel.Customer'}: 
                      {foreach from=$book.product.formats key=k item=format}
                        <a href="{$format.download_url}">{$format.format}</a>{if $product_count-1 != $k}, {/if} 
                      {/foreach}
                    {else}
                      <th>{l s='Knihy se připravují' d='Modules.Miguel.Customer'}</th>
                    {/if}
                  {else}
                    {$book.order_state}
                  {/if}
                </div>
              </div>
            </div>
          </div>
        {/foreach}
      </div>
    {else}
      <h6>{l s='Dosud jste nezakoupili žádné knihy.' d='Modules.Miguel.Customer'}</h6>
    {/if}
    </div>
  </section>
{/block}











