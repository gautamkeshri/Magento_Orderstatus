<?php
/** 
 * @package     Ndsl_orderstatus
 * @author      Ndsl Team <gautam@ndslindia.com>
 * @copyright   Copyright (c) 2014 - Net Distribution Services Pvt Ltd. (http://ndslindia.com/) 
 */
class Ndsl_Orderstatus_ImportController extends Mage_Adminhtml_Controller_Action
{
	/**
     * Constructor
     */
    protected function _construct()
    {        
        $this->setUsedModuleName('Ndsl_Orderstatus');
    }

    /**
     * Main action : show import form
     */
    public function indexAction()
    {
        $this->loadLayout()
            ->_setActiveMenu('sales/orderstatus/import')
            ->_addContent($this->getLayout()->createBlock('orderstatus/import_form'))
            ->renderLayout();
    }

    /**
     * Import Action
     */
    public function importAction()
    {
        if ($this->getRequest()->isPost() && !empty($_FILES['import_orderstatus_file']['tmp_name'])) {
            try {
                $checkbox_state = 0;
                $comment = $_POST['comments'];
                $gorderstatus = $_POST['orderstatus_code'];
                $checkbox_state = $_POST['createinvoce'];
                
                $this->_importTrackingFile($_FILES['import_orderstatus_file']['tmp_name'],$comment,$gorderstatus,$checkbox_state);
            }
            catch (Mage_Core_Exception $e) {
                $this->_getSession()->addError($e->getMessage());
            }
            catch (Exception $e) {
                $this->_getSession()->addError($e->getMessage());
                $this->_getSession()->addError($this->__('Invalid file upload attempt'));
            }
        }
        else {
            $this->_getSession()->addError($this->__('Invalid file upload attempt'));
        }
        $this->_redirect('*/*/index');
    }

    /**
     * Importation logic
     * @param string $fileName
     * @param string $comment
     */
    protected function _importTrackingFile($fileName,$comment,$gorderstatus,$checkbox_state)
    {
        /**
         * File handling
         **/
        ini_set('auto_detect_line_endings', true);
        $csvObject = new Varien_File_Csv();
        $csvData = $csvObject->getData($fileName);

        /**
         * File expected fields
         */
        $expectedCsvFields  = array(
            0   => $this->__('Order Id')
        );

        /**
         * $k is line number
         * $v is line content array
         */
        foreach ($csvData as $k => $v) 
        {
            if ($k == 0) {
                 continue;
              }
            /**
             * End of file has more than one empty lines
             */
            if (count($v) <= 1 && !strlen($v[0])) {
                continue;
            }

            /**
             * Check that the number of fields is not lower than expected
             */
            if (count($v) < count($expectedCsvFields)) {
                $this->_getSession()->addError($this->__('Line %s format is invalid and has been ignored', $k));
                continue;
            }

            /**
             * Get fields content
             */
            $orderId = $v[0];

            if ($orderId =='') {                
                continue;
            }
            /* for debug */
            //$this->_getSession()->addSuccess($this->__('Lecture ligne %s: %s', $k, $orderId));

            /**
             * Try to load the order
             */
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            if (!$order->getId()) {
                $this->_getSession()->addError($this->__('Order %s does not exist', $orderId));
                continue;
            }

            /**
             * Try to change order status 
             */
            $orderprocess = $this->_changeStatus($order,$comment,$gorderstatus,$checkbox_state);

            if ($orderprocess) {
                $this->_getSession()->addSuccess($this->__('Order status changed for order %s', $orderId));
            }
        }//foreach

    }

    /**
     * Create new shipment for order
     * Inspired by Mage_Sales_Model_Order_Shipment_Api methods
     *
     * @param Mage_Sales_Model_Order $order (it should exist, no control is done into the method)
     * @param string $comment
     * @return staus
     */
    public function _changeStatus($order,$comment,$gorderstatus,$checkbox_state)
    {
            //Mage::log($order->getState()." - ".$gorderstatus." - checkbox- ".$checkbox_state,null,'ostatus.log'); 
            if($gorderstatus == "default"){
                $gorderstatus = $order->getStatus();
                //Mage::log($order->getState()." - ".$gorderstatus." - checkbox- ".$checkbox_state,null,'ostatus.log');    
            }
                $gorderstatus = $gorderstatus;
            
                             
            if($checkbox_state == "1"){
                try 
                {
                    if(!$order->canInvoice()) 
                    {
                        Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
                    }
                    $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                    if (!$invoice->getTotalQty()) {
                        Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
                    }
                    //$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                    $invoice->register();
                    $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                    $transactionSave->save();
                } catch (Mage_Core_Exception $e) {
                    $this->_getSession()->addError($this->__('Order %s Error in Invoice ', $order->getIncrementId()));
                }    
            }                    
            

            $gstate = $this->_getAssignedState($gorderstatus);
            if($gstate == $order->getState()){
                $order->setCustomerNote($comment)->setCustomerNoteNotify(true)
                      ->addStatusToHistory(
                            $gorderstatus,
                            $order->getCustomerNote(),
                            $order->getCustomerNoteNotify())
                            ->sendOrderUpdateEmail($order->getCustomerNoteNotify(), $order->getCustomerNote())
                            ->save();
            }else{
                $gstate = $this->_getAssignedState($gorderstatus);
                $isCustomerNotified = false;
                $order->setState($gstate, $gorderstatus, $comment, false)->save();
            }
            

               
            $this->_getSession()->addSuccess($this->__('Email send to  %s',$order->getIncrementId()));                       
    }

    protected function _getAssignedState($status)
    {
        $item = Mage::getResourceModel('sales/order_status_collection')
            ->joinStates()
            ->addFieldToFilter('main_table.status', $status)
            ->getFirstItem();
 
        return $item->getState();
    }
}
?>