(jQuery)(function() {

    $ = (jQuery);

    $("#gform-settings-save").click(function(e) {

        var secret =  randomString();
        $('#gf_razorpay_webhook_secret').val(secret);
        var rzp  = $('#gf_razorpay_key').attr("name");

        if( typeof(rzp) != "undefined" && rzp.indexOf('razorpay') != -1 ){

                $.ajax({
                    type : "POST",
                    dataType : "json",
                    url : ajaxurl,
                    async: false,
                    data : {
                        action: "get_data",
                        webhook_secret: secret
                    },
                    success: function(response) {
                        console.log(response)
                    }
            });
        }

        $("#gform-settings-save").submit();
    });

    function randomString()
    {
        var chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-=~!@#$%^&*()_+,./<>?;:[]{}|abcdefghijklmnopqrstuvwxyz";
        var string_length = 20;
        var randomstring = '';

        for (var i = 0; i < string_length; i++)
        {
            var rnum = Math.floor(Math.random() * chars.length);
            randomstring += chars.substring(rnum, rnum + 1);
        }

        return randomstring;
    }
});
