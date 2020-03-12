# Paypal Account Transaction Processor (PATP)
Process PayPal PDT/IPN messages and link them to simple account modifications.

# Getting Started
Implement these interfaces:

``` php
interface IAccountManager {		
	public function getId($data);
	public function isCreated($id);
	public function isActive($id);
	public function create($id, $data);
	public function setActive($id, $active, $data);
}

interface ITransactionLogger {		
	public function logTransaction($id, $data);
}

interface INotifier {		
	public function notify($msg);
}
```

Then dependency inject the implementations into `PTAP`, along with a <a href='https://www.php.net/manual/en/class.closure.php'> closure function</a> that depicts what accounts actions you want to take based on <a href='https://developer.paypal.com/docs/ipn/integration-guide/IPNandPDTVariables/'>PDT/IPN variables</a> (checkout <a href='https://gist.github.com/BHare1985/7fc719ba3a86c5c499ccdf55d5645e8b'>this gist</a> for a opinionated but more encompassing closure):

``` php
$ptap = new PTAP(new DummyAccount(), new DummyLogger(), new DummyNotifier(), function($data){
	if($data['txn_type'] == "web_accept") {
		return array(Action::create, Category::payment);
	} else if($data['txn_type'] == "new_case"){
		return array(Action::deactivate, Category::dispute);		
	}
});

$ptap->process($_POST);
```

# Demo
A very simple demostration of an implmentation that will create accounts when a new subscriber is created. Deactivate accounts if a payment is missed or a dispute is created, and re-activate accounts if a new payment comes in.  The `$repo` variable is meant to represent a repository abstraction (such a database).

``` php
include("PATP.php");

class DummyAccount implements IAccountManager {
    private $repo = array();
    
    public function getId($data){
        return $data['payer_email'];
    }
    public function isCreated($id){
        return isset($this->repo[$id]);
    }
    public function isActive($id){
        return $this->repo[$id]["active"];
    }
    public function create($id, $data){
        $this->repo[$id] = array(
            "name" => $data["first_name"],
            "password" => "foobar",
            "email" => $data["payer_email"],
            "active" => true
        );
        
        $this->email($id, "Hey {$this->repo[$id]["name"]}, your password is {$this->repo[$id]["password"]}");
    }
    public function setActive($id, $active, $data){
        $this->repo[$id]["active"] = $active;
    }   
    private function email($id, $msg){
        $email = $this->repo[$id]["email"];
        print "[EMAIL] sent mail to $email -> $msg<br>\n";
    }
}

class DummyLogger implements  ITransactionLogger {      
    public function logTransaction($id, $data){
        print "[LOG] $id: " . var_export($data, true) . "<br>\n";
    }
}

class DummyNotifier implements  INotifier {     
    public function notify($message){
        print "[NOTIFY] $message <br>\n";
    }
}

$ptap = new PTAP(new DummyAccount(), new DummyLogger(), new DummyNotifier(), function($data){
    switch($data["txn_type"]){
        //=========== SUBSCRIPTIONS                 
        case "subscr_signup":   //Subscription started
            return array(Action::create, Category::subscription);
        case "subscr_payment":  //Subscription payment received
            return array(Action::activate, Category::subscription);
        case "subscr_cancel":   //Subscription canceled
        case "subscr_eot":      //Subscription expired
        case "subscr_failed":   //Subscription payment failed
            return array(Action::deactivate, Category::subscription);
        case "subscr_modify":   //Subscription modified
            return array(Action::nop, Category::subscription);
            
        //=========== DISPUTES      
        case "adjustment":      //A dispute has been resolved and closed
            return array(Action::nop, Category::dispute);           
        case "new_case":        //A new dispute was filed
            return array(Action::deactivate, Category::dispute);
        default:
            throw new Exception("Unexpected transaction type $type");
    }
});
```

And then mocking a PayPal POST request to see it in action:
``` php
$PaypalData_Payment= array(
	"txn_type" => "subscr_payment",
	"subscr_id" => "I-W3V7E2U39WJM",
	"first_name" => "Joe Bob",
	"payer_email" => "jbob@gm.com"
);

$PaypalData_NewSubscriber = array(
	"txn_type" => "subscr_signup",
	"subscr_id" => "I-W3V7E2U39WJM",
	"first_name" => "Joe Bob",
	"payer_email" => "jbob@gm.com"
);

$PaypalData_ReactivateSubscriber = array(
	"txn_type" => "subscr_modify",
	"subscr_id" => "I-W3V7E2U39WJM",
	"payer_email" => "jbob@gm.com"
);

$PaypalData_Dispute = array(
	"txn_type" => "new_case",
	"case_id" => "CASE444",
	"payer_email" => "jbob@gm.com"
);

$PaypalData_CancelSubscriber = array(
	"txn_type" => "subscr_cancel",
	"subscr_id" => "I-W3V7E2U39WJM",
	"payer_email" => "jbob@gm.com"
);

$ptap->process($PaypalData_CancelSubscriber);		// Notify
$ptap->process($PaypalData_NewSubscriber);		// Make account
$ptap->process($PaypalData_CancelSubscriber);		// Deactivate account
$ptap->process($PaypalData_ReactivateSubscriber);	// Do nothing
$ptap->process($PaypalData_Payment);			// Reactivate account
$ptap->process($PaypalData_Dispute);			// Deactivate account
$ptap->process($PaypalData_CancelSubscriber);		// Notify
 ```

Yields the following output:

```
[LOG] I-W3V7E2U39WJM: array ( 'txn_type' => 'subscr_cancel', 'subscr_id' => 'I-W3V7E2U39WJM', 'payer_email' => 'jbob@gm.com', )
[NOTIFY] Attempted to set account ID jbob@gm.com activeness to false, but the account wasnt created
[LOG] I-W3V7E2U39WJM: array ( 'txn_type' => 'subscr_signup', 'subscr_id' => 'I-W3V7E2U39WJM', 'first_name' => 'Joe Bob', 'payer_email' => 'jbob@gm.com', )
[EMAIL] sent mail to jbob@gm.com -> Hey Joe Bob, your password is foobar
[LOG] I-W3V7E2U39WJM: array ( 'txn_type' => 'subscr_cancel', 'subscr_id' => 'I-W3V7E2U39WJM', 'payer_email' => 'jbob@gm.com', )
[LOG] I-W3V7E2U39WJM: array ( 'txn_type' => 'subscr_modify', 'subscr_id' => 'I-W3V7E2U39WJM', 'payer_email' => 'jbob@gm.com', )
[LOG] I-W3V7E2U39WJM: array ( 'txn_type' => 'subscr_payment', 'subscr_id' => 'I-W3V7E2U39WJM', 'first_name' => 'Joe Bob', 'payer_email' => 'jbob@gm.com', )
[LOG] CASE444: array ( 'txn_type' => 'new_case', 'case_id' => 'CASE444', 'payer_email' => 'jbob@gm.com', )
[LOG] I-W3V7E2U39WJM: array ( 'txn_type' => 'subscr_cancel', 'subscr_id' => 'I-W3V7E2U39WJM', 'payer_email' => 'jbob@gm.com', )
[NOTIFY] Attempted to set account ID jbob@gm.com activeness to false, but it already was.
```
