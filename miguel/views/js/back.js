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

function setInputServer(){
   var api_server = $('#API_SERVER').val();
  $(".input_server").each(function() {
    var input_id = $(this).attr('name');
    //console.log(input_id + ' '+api_server);
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
