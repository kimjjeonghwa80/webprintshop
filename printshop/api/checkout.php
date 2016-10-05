<?php
/**
 *  
 *  This call charges the user's card 
 *  and marks the order as complete
 *  
 */
require __DIR__.'/autoload.php';
use Symfony\Component\Yaml\Yaml;
use Carbon\Carbon;
use Kidino\Billplz\Billplz;

$settings = include __DIR__ . "/../data/settings.php";
echo $order->payment_method;

// Retrieve the request's body and parse it as JSON
$input = @file_get_contents("php://input");
$event_json = json_decode($input);

$order = Order::find($event_json->orderId);
if(!$order)
	die();


$payment_settings = Yaml::parse(file_get_contents(__DIR__ . '/../data/payment_methods/'.$order->payment_method.'.yml'));

if($order->payment_method == 'stripe') {
	
	// Set your secret key: remember to change this to your live secret key in production
	// See your keys here https://dashboard.stripe.com/account
	Stripe::setApiKey($payment_settings['secret_key']);

	$order->payment_details = json_encode($event_json->token);
	$order->save();
	
	// Do something with $event_json
	$token = $event_json->token->id;
	// Create the charge on Stripe's servers - this will charge the user's card
	try {
		$charge = Stripe_Charge::create(array(
		  "amount" => $order->amount*100, // amount in cents, again
		  "currency" => $order->currency,
		  "card" => $token,
		  "description" => $event_json->token->email)
		);
		
		$order->charge = json_encode($charge->__toArray(true));
		$order->transaction_id = $charge->id;
		$order->payment_status = 'paid';
		$order->fulfillment_status = 'awaiting_processing';
		$order->confirmed = 1;
		$order->save();
	} catch(Stripe_Error $e) {
	  // The card has been declined
	  $order->info = json_encode($e);
	  $order->confirmed = -1;
	  $order->save();
	}

} else if($order->payment_method == 'bank_transfer') {
	//set it to confirmed
	$order->confirmed = 1;
	$order->save();

} else if($order->payment_method == 'billplz') {
	$billplz_response = null;
   echo 'BillPlz Checkout is Passed  '. $order->payment_method;
   
   $payment_details = json_decode($order->payment_details);
   $checkout_details = json_decode($order->checkout_details);

   echo '\n Checkout DEtail '. $checkout_details->description;

		//extract data from the post
		//set POST variables
		$url = 'https://www.billplz.com/api/v3/bills';
		$fields = array(
		'collection_id' => 'waqfvbke',
	    'email' => $order->email,
	    'name' => $order->email,
	    'amount' => $order->amount*100, // amount in cents, again
	    'callback_url' => "http://api.dropp.photo/payment/billplz/callback/",
	    'description'=>$checkout_details->title
		);

		//url-ify the data for the POST
		foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
		rtrim($fields_string, '&');


		try {
		    $ch = curl_init();

		    if (FALSE === $ch)
		        throw new Exception('failed to initialize');

				//echo $ch;
				//set the url, number of POST vars, POST data
		        curl_setopt($ch, CURLOPT_HEADER, 1);
				curl_setopt($ch,CURLOPT_URL, 'https://www.billplz.com/api/v3/bills');
				curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, 0);
				// curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
				// curl_setopt($ch,CURLOPT_FOLLOWLOCATION, 1);

				// curl_setopt($ch,CURLOPT_FAILONERROR, 1);	
				curl_setopt($ch,CURLOPT_POST, count($fields));
				curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
				curl_setopt($ch, CURLOPT_USERPWD, "222527fc-1ed8-49c4-9a55-459b16bcab36:");

				//execute post
				//$result1 = curl_exec($ch);

		    	$content = curl_exec($ch);

		    		$order->confirmed = 1;
					$order->save();


				
		    if (FALSE === $content)
		        throw new Exception(curl_error($ch), curl_errno($ch));
		   
			$billplz_response = json_decode($content, true);

		    // ...process $content now
		} catch(Exception $e) {

		   echo $e;
		}
		echo '              url IS ' . $billplz_response[0]['url'];


}	



//now show the order details
$result = [];
$result['status'] = true;
$data_dir = __DIR__ . "/../data/";
$pricing = Yaml::parse($data_dir . "pricing.yml");

$order = Order::find($event_json->orderId);
$payment_details = json_decode($order->payment_details);
$checkout_details = json_decode($order->checkout_details);
$result['amount'] = number_format($order->amount, 2);
$result['id'] = $order->id;
$result['currency'] = $order->currency;
$result['date'] = Carbon::parse($order->created_at)->toDateTimeString();
$result['transaction_id'] = str_replace("ch_", "", $order->transaction_id);
$result['postage'] = $pricing['delivery_types'][$checkout_details->postage]['name'];
$result['description'] = $checkout_details->title;
$result['email'] = $order->email;
$result['payment_status'] = $order->payment_status;
$result['payment_method'] = $order->payment_method;
$result['instructions'] = nl2br($payment_settings['instructions']);


$_SESSION['uuid'] = generate_session()->toString();	 //reset session

//send an email
/*
$mail = new MyMailer;
#$mail->isSendmail();
$mail->setFrom($settings['email'], $settings['site_name']);	//Set who the message is to be sent from
$mail->addAddress($order->email, $order->firstname .' '. $order->lastname); //Set who the message is to be sent to
$mail->Subject = 'Your '.$settings['site_name'].' order';

$email_template = __DIR__ .'/../data/emails/order_placed.'.$order->payment_method.'.txt';
if( !file_exists($email_template)  ) {
	$email_template = __DIR__ .'/../data/emails/order_placed.txt';	
}
$message = file_get_contents($email_template);
$message = str_replace("{name}", $order->firstname .' '. $order->lastname, $message);
if(isset($payment_settings['instructions'])) { //if we have instructions
	$message = str_replace("{instructions}", $payment_settings['instructions'], $message);
}
$message = str_replace("{transaction_id}", $order->id, $message);
$message = str_replace("{site_name}", $settings['site_name'], $message);
$mail->Body = $message;  
if(!$mail->send()) {
	echo 'Message could not be sent.';
	echo 'Mailer Error: ' . $mail->ErrorInfo;
} else {
	echo 'Message has been sent';
}
*/

header('Content-Type: application/json');
echo json_encode($result);

