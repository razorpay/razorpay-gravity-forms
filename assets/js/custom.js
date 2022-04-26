

(jQuery)(function() {
   
    $ = (jQuery);
    $("#gform-settings-save").click(function() {

        var rzp  = $('#gf_razorpay_key').attr("name");
        if( typeof(rzp) != "undefined" && rzp.indexOf('razorpay') != -1 ){

             $.ajax({
                 type : "POST",
                 dataType : "json",
                 url : ajaxurl,
                 async: false,
                 data : {
                     action: "get_data"
     
             },
                 success: function(response) {
     
                      console.log(response);
                    }   
            });
        }
        $("#gform-settings-save").submit();
         
    });
});
