var script = document.createElement('script');
script.src = "https://checkout.razorpay.com/v1/checkout.js";

window.onload  = function(e)
{
    document.body.appendChild(script);

    script.onload = function()
    {
        var script2  = document.createElement("script");

        var div = document.createElement('div');

        var html = "<form id ='razorpayform' name='razorpayform' method='POST'> " +
        "<input type='hidden' name='razorpay_payment_id' id='razorpay_payment_id'> " +
        "<input type='hidden' name='razorpay_signature'  id='razorpay_signature' > " +
        "</form>" +
        "<p id='msg-razorpay-success'  style='display:none'>"+
        "Please wait while we are processing your payment."+
        "</p>" +
        "<p>" +
        "<button id='btn-razorpay'>Pay Now</button>" +
        "<button id='btn-razorpay-cancel' onclick='document.razorpayform.submit()'>Cancel</button>" +
        "</p>"; 

        div.innerHTML =  html;

        document.body.appendChild(div);

        document.getElementById('razorpayform').action = razorpay_script_vars.redirect_url;

        function invokeRZP()
        {
            var data = razorpay_script_vars.data;

            var setDisabled = function(id, state) {
                if (typeof state === 'undefined')
                {
                    state = true;
                }

                var elem = document.getElementById(id);

                if (state === false)
                {
                    elem.removeAttribute('disabled');
                }
                else
                {
                    elem.setAttribute('disabled', state);
                }
            };
            // Payment was closed without handler getting called
            data.modal =
            {
                ondismiss: function()
                {
                    setDisabled('btn-razorpay', false);
                }
            };

            data.handler = function(payment)
            {
                setDisabled('btn-razorpay-cancel');
                var successMsg = document.getElementById('msg-razorpay-success');
                successMsg.style.display = 'block';
                document.getElementById('razorpay_payment_id').value = payment.razorpay_payment_id;
                document.getElementById('razorpay_signature').value = payment.razorpay_signature;
                document.razorpayform.submit();
            };

            var razorpayCheckout = new Razorpay(data);

            // global method
            function openCheckout()
            {
                // Disable the pay button
                setDisabled('btn-razorpay');
          
                razorpayCheckout.open();
            }

            function addEvent(element, evnt, funct)
            {
                if (element.attachEvent)
                    return element.attachEvent('on'+evnt, funct);
                else
                    return element.addEventListener(evnt, funct, false);
            }
            // Attach event listener
            addEvent(document.getElementById('btn-razorpay'), 'click', openCheckout);
            setTimeout(openCheckout);
        };

        invokeRZP();
    }
}