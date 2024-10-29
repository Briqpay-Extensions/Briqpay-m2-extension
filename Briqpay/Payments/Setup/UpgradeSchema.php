<?php
namespace Briqpay\Payments\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
use Briqpay\Payments\Logger\Logger;

class UpgradeSchema implements UpgradeSchemaInterface
{
    protected $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->logger->info('UpgradeSchema: logger instantiated');
    }

    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $this->logger->info('UpgradeSchema: start');

        if (version_compare($context->getVersion(), '1.0.11', '<')) {
            $this->logger->info('UpgradeSchema: version is less than 1.0.11');

            $this->createOrderTableColumns($setup);
            $this->createQuoteTableColumns($setup);
            $this->createSalesOrderColumns($setup);

            $this->createCaptureRelationTable($setup);

            $setup->endSetup();
            $this->logger->info('UpgradeSchema: end');
        }
    }
    private function createCaptureRelationTable($setup)
    {
// Create table 'payment_capture_mapping'
        if (!$setup->tableExists('payment_capture_mapping')) {
            $table = $setup->getConnection()->newTable($setup->getTable('payment_capture_mapping'))
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'nullable' => false, 'primary' => true],
                    'Entity ID'
                )
                ->addColumn(
                    'invoice_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false],
                    'Invoice ID'
                )
                ->addColumn(
                    'order_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false],
                    'Order ID'
                )
                ->addColumn(
                    'item_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false],
                    'Item ID'
                )
                ->addColumn(
                    'capture_id',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Capture ID'
                )
                ->addColumn(
                    'quantity',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false, 'default' => 0],
                    'Quantity'
                )
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => 0],
                    'Quantity'
                )
                ->setComment('Payment Capture Mapping Table');
            $setup->getConnection()->createTable($table);
        }
    }
    private function createColumnsInDb($setup, $table, $columns)
    {
        $quoteTableName = $setup->getTable($table);
         // Define columns to be added to sales_order
         
          // Add columns to sales_order table
        if ($setup->getConnection()->isTableExists($quoteTableName)) {
            $this->logger->info('UpgradeSchema: '.$table.' table exists');
            foreach ($columns as $name => $definition) {
                if (!$setup->getConnection()->tableColumnExists($quoteTableName, $name)) {
                    $setup->getConnection()->addColumn($quoteTableName, $name, $definition);
                    $this->logger->info('UpgradeSchema: added column ' . $name . ' to '.$table);
                } else {
                    $this->logger->info('UpgradeSchema: column ' . $name . ' already exists in '.$table);
                }
            }
        } else {
            $this->logger->info('UpgradeSchema: '.$table.' table does not exist');
        }
    }
    private function createQuoteTableColumns($setup)
    {

         // Define columns to be added to sales_order
         $quoteColumns = [
               
            'briqpay_session_id' => [
        'type' => Table::TYPE_TEXT,
        'nullable' => true,
        'comment' => 'Briqpay Session ID'
            ],
            'briqpay_psp_display_name' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'PSP Display Name'
            ],
         ];
         $this->createColumnsInDb($setup, 'quote', $quoteColumns);
          // Add columns to sales_order table
    }
    private function createSalesOrderColumns($setup)
    {
    
         // Define columns to be added to sales_invoice
         $invoiceColumns = [
            'briqpay_capture_id' => [
        'type' => Table::TYPE_TEXT,
        'nullable' => true,
        'comment' => 'Briqpay Capture ID'
            ],
         ];
         $this->createColumnsInDb($setup, 'sales_invoice', $invoiceColumns);
        // Add columns to sales_invoice table
    }
    
    private function createOrderTableColumns($setup)
    {
       
        // Define columns to be added to sales_order
        $orderColumns = [
            'briqpay_psp_display_name' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'PSP Display Name'
            ],
            'briqpay_session_id' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay Session ID'
            ],
            'briqpay_psp_reservationId' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay PSP Reservation ID'
            ],
            'briqpay_backoffice_url' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay backoffice url'
            ],
            'briqpay_session_status' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay session status'
            ],
            'briqpay_cin' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay cin field'
            ],
            'briqpay_company_name' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay companyname collected'
            ],
            'briqpay_company_vatNo' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay company vatno collected'
            ],
            'briqpay_strong_auth' => [
                'type' => Table::TYPE_TEXT,
                'nullable' => true,
                'comment' => 'Briqpay strong auth data'
            ],
        ];
        $this->createColumnsInDb($setup, 'sales_order', $orderColumns);
// Add columns to sales_order table
    }
}
