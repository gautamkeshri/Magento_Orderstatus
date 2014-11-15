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
                $comment = $_POST['comments'];
                
                $this->_importTrackingFile($_FILES['import_orderstatus_file']['tmp_name'],$comment);
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
    protected function _importTrackingFile($fileName,$comment)
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
            $orderprocess = $this->_changeStatus($order,$comment);

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
    public function _changeStatus($order,$comment)
    {
            $order->setCustomerNote($comment)->setCustomerNoteNotify(true)
                                ->addStatusToHistory(
                                    //$order->getState(),
                                    'processing',
                                    $order->getCustomerNote(),
                                    $order->getCustomerNoteNotify())
                                ->sendOrderUpdateEmail($order->getCustomerNoteNotify(), $order->getCustomerNote())
                                ->save();
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
            $this->_getSession()->addSuccess($this->__('Email send to  %s',$order->getIncrementId()));                       
    }
}
?>