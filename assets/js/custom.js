(jQuery)(function() {
   
    $ = (jQuery);
    $("#gform-settings-save").click(function(e) {
  
    $secret = Math.round((Math.pow(36, 8 + 1) - Math.random() * Math.pow(36,8))).toString(36).slice(1);
    $('#gf_razorpay_webhook_secret').val($secret);
        var rzp  = $('#gf_razorpay_key').attr("name");
        if( typeof(rzp) != "undefined" && rzp.indexOf('razorpay') != -1 ){

             $.ajax({
                 type : "POST",
                 dataType : "json",
                 url : ajaxurl,
                 async: false,
                 data : {
                     action: "get_data",
                     webhook_secret: $secret
                },
                 success: function(response) {
                    console.log(response)
                }   
            });
        }
        $("#gform-settings-save").submit();  
    });
});
