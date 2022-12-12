/**
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
*
* Don't forget to prefix your containers with your own identifier
* to avoid any conflicts with others containers.
*/

function setInputServer(){
   var api_server = $('#API_SERVER').val();
  $(".input_server").each(function() {    
    var input_id = $(this).attr('name');
    console.log(input_id + ' '+api_server);
    if(input_id == api_server) {
      $(this).parent().parent().css( "display", "" );
    }
    else { 
      if(input_id == "API_SERVER_OWN" && api_server == "API_TOKEN_OWN")
        $(this).parent().parent().css( "display", "" );
      else 
        $(this).parent().parent().css( "display", "none" );
    }
  });
}

$(document).ready(function(){

  $('#API_SERVER').on('change', function() {  
    setInputServer();  
  });

  setInputServer();
  $(".form-wrapper").toggle(); // zviditelním nastavení  

});












