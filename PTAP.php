<?php
	// ===========================
	// ========== ENUMS ==========
	// ===========================
	
	abstract class Category { 
		const payment		= "payments";
		const dispute		= "disputes"; 
		const subscription	= "subscriptions";
		const recurring		= "recurringPayments";
	}
	
	abstract class Action { 
		const nop		 = 0;
		const create	 = 1;
		const activate	 = 2; 
		const deactivate = 3;
	}
	
	
	// ================================
	// ========== INTERFACES ==========
	// ================================
	
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
	
	// ================================
	// =========== HELPERS ============
	// ================================
	
	class AccountProcessor {
		
		private $_accountProcessor;
		private $_notifier;
		
		function __construct(IAccountManager $accountManager, INotifier $notifer) {
			$this->_accountProcessor = $accountManager;
			$this->_notifier = $notifer;
		}
	
		public function process($action, $data){
			
			$id = $this->_accountProcessor->getId($data);
			
			//Using abstract classes to fake enums isn't great, so I must use the value
			switch($action){
				case 0: //Action::nop:
					return;
				case 1: //Action::create:
					return $this->create($id, $data);
				case 2: //Action::activate:
					return $this->setActive($id, $data, true);
				case 3: //Action::deactivate:
					return $this->setActive($id, $data, false);
				default:
					throw new Exception("Unexpected category $category");		
			}	
		}		
		
		public function create($id, $data){
			if($this->_accountProcessor->isCreated($id)){
				$this->_notifier->notify("Attempted to create account ID $id, but it already exists.");
				return null;
			}
			
			return $this->_accountProcessor->create($id, $data);
		}
		
		public function setActive($id, $data, $active){
			$activeStr = $active ? 'true' : 'false';

			if(!$this->_accountProcessor->isCreated($id)){
				$this->_notifier->notify("ERROR: Attempted to set account ID $id activeness to $activeStr, but the account wasn't created.");
				return null;
			}
			
			if($this->_accountProcessor->isActive($id) == $active){
				$this->_notifier->notify("ERROR: Attempted to set account ID $id activeness to $activeStr, but it already was.");
				return null;
			}
			
			return $this->_accountProcessor->setActive($id, $active, $data);			
		}
	}

	class PTAP {		
		private $_accountProcessor;
		private $_logger;
		private $_handlerFunc;
		
		function __construct(IAccountManager $accountManager, ITransactionLogger $logger, INotifier $notifer, $handlerFunc) {
			$this->_accountProcessor = new AccountProcessor($accountManager, $notifer);
			$this->_logger = $logger;
			$this->_handlerFunc = $handlerFunc;
		}
	
		public function process($data){
			if(!isset($data)) {
				throw new Exception("Missing \$data");
			}
			
			$fn = $this->_handlerFunc;
			list($action, $category) = $fn($data);
			
			$this->logTransaction($category, $data);
			
			return $this->_accountProcessor->process($action, $data);				
		}	

		private function logTransaction($category, $data){
			$logId = $data[$this->getLogId($category)];
			if(!isset($logId)) {
				throw new Exception("Missing logId a.k.a \$data[$key] for category $category");
			}
			
			$this->_logger->logTransaction($logId, $data);
		}

		private function getLogId($category){
			switch($category){
				case Category::payment:
					return "txn_id";
				case Category::subscription:
					return "subscr_id";
				case Category::recurring:
					return "recurring_payment_id";
				case Category::dispute:
					return "case_id";
				default:
					throw new Exception("Unexpected category $category");
			}
		}	
	}
?>