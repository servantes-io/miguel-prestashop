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


{extends file='customer/page.tpl'}

{block name='page_title'}
  {$miguelTitlePage}
{/block}

{block name='page_content'}
  <section  id="history" class="page-customer-account">
    <div id="content" class="page-content">
    {if (count($miguel_purchased) > 0) && ('debug'|array_key_exists:$miguel_purchased) == false}
      <h6>{l s='Here are all the eBooks you have purchased with this customer account.' mod='miguel'}</h6>
      <table class="table table-striped table-bordered table-labeled hidden-sm-down">
        <thead class="thead-default">
          <tr>
            <th>{l s='Book Title' mod='miguel'}</th>
            <th>{l s='Order Reference' mod='miguel'}</th>
            <th>{l s='Purchase Date' mod='miguel'}</th>
            <th>{l s='Download' mod='miguel'}</th>
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
                    <th>{l s='The books are being prepared' mod='miguel'}</th>
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
                <div class="">{l s='Order Reference' mod='miguel'}: {$book.reference}</div>
                <div class="">{l s='Purchase Date' mod='miguel'}: {$book.date_add}</div>
                <div class="">
                  {if $book.paid}
                    {$product_count = count($book.product.formats)}
                    {if $product_count > 0}
                      {l s='Download' mod='miguel'}: 
                      {foreach from=$book.product.formats key=k item=format}
                        <a href="{$format.download_url}">{$format.format}</a>{if $product_count-1 != $k}, {/if} 
                      {/foreach}
                    {else}
                      <th>{l s='The books are being prepared' mod='miguel'}</th>
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
      <h6>{l s='You have not purchased any books yet.' mod='miguel'}</h6>
    {/if}
    </div>
  </section>
{/block}











