$.ajax({
    url: 'check_username.php',
    type: 'POST',
    data: {username: username},
    dataType: 'json',
    success: function(response) {
        if (response.available) {
            $('#username_status').html('<span style="color:green">' + response.message + '</span>');
        } else {
            $('#username_status').html('<span style="color:red">' + response.message + '</span>');
        }
    }
});
