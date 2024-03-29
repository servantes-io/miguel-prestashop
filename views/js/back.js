function tokenInputNameForServer(server) {
  if (server == 'prod') {
    server = 'production';
  }

  return "MIGUEL_API_TOKEN_" + server.toUpperCase();
}

function setInputServer(apiServer) {
  const tokenInputName = tokenInputNameForServer(apiServer);

  $(".input_server").each(function () {
    const inputName = $(this).attr('name');

    if (inputName == tokenInputName) {
      $(this).parent().parent().css("display", "");
    } else {
      if (inputName == "MIGUEL_API_SERVER_OWN" && apiServer == "own") {
        $(this).parent().parent().css("display", "");
      } else {
        $(this).parent().parent().css("display", "none");
      }
    }
  });
}

function updateServerInput(allowOtherEnvironments) {
  const apiServer = $('#MIGUEL_API_SERVER').val();

  if (allowOtherEnvironments) {
    setInputServer(apiServer);
  } else {
    setInputServer('prod');
    $('#MIGUEL_API_SERVER').parent().parent().css("display", "none");
  }
}

$(document).ready(function () {
  const allowOtherEnvironments = localStorage.getItem('MIGUEL_ALLOW_OTHER_ENVIRONMENTS') === 'true';

  $('#MIGUEL_API_SERVER').on('change', function () {
    updateServerInput(allowOtherEnvironments);
  });

  updateServerInput(allowOtherEnvironments);

  $(".form-wrapper").toggle(); // zviditelním nastavení

});
