<?php

namespace Vivekmehta\Customerimport\Model;

use Exception;
use Generator;
use Magento\Framework\Filesystem\Io\File;
use Magento\Store\Model\StoreManagerInterface;
use Vivekmehta\Customerimport\Model\Import\CustomerImport;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Serialize\SerializerInterface; 
class Customer
{   
    /**
     * @var \Magento\Customer\Api\AccountManagementInterface
     */
    protected $accountManagement;
    private $file;
    private $storeManagerInterface;
    /**
    * @var \Magento\Framework\HTTP\Client\Curl
    */
    protected $_curl;
    private $output;
        /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
        protected $customerFactory;

        /**
     * @var \Magento\Customer\Model\ResourceModel\Customer
     */
        protected $customerResource;
        protected $json;
     /**
     * @var SerializerInterface
     */
     protected $serializer;
    /**
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param \Magento\Customer\Model\CustomerFactory    $customerFactory
      * @param \Magento\Customer\Model\ResourceModel\Customer $customerResource
     */
    public function __construct(
        File $file,
        StoreManagerInterface $storeManagerInterface,
        \Magento\Customer\Api\AccountManagementInterface $accountManagement,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Customer\Model\ResourceModel\Customer $customerResource,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem\Driver\File $driverFile,
        \Magento\Framework\Serialize\Serializer\Json $json,
        SerializerInterface $serializer

    ) {
        $this->file = $file;
        $this->storeManagerInterface = $storeManagerInterface;
        $this->customerFactory  = $customerFactory;
        $this->accountManagement = $accountManagement;
        $this->customerResource = $customerResource;
        $this->_curl = $curl;
        $this->directoryList =$directoryList;
        $this->driverFile = $driverFile;
        $this->json = $json;
        $this->serializer = $serializer;

    }
    public function install(string $fixture, OutputInterface $output, $fileExtension): void
    {
        $this->output = $output; 
        $store = $this->storeManagerInterface->getStore();
        $websiteId = (int) $this->storeManagerInterface->getWebsite()->getId();
        $storeId = (int) $store->getId();
        if ($fileExtension == 'csv') {
            $header = $this->readCsvHeader($fixture)->current();

            $row = $this->readCsvRows($fixture, $header);
            $row->next();

            while ($row->valid()) {
        $data = $row->current(); 
        $this->updateCustomer($data, $websiteId, $storeId);
        $row->next();
    }
} else {

    try {

        $result =  $this->driverFile->fileGetContents($fixture);
        $string = preg_replace('/[\x00-\x1F\x7F]/u', '', $result);
        $newData=str_replace('?', '', utf8_decode($string));
        $customerData=json_decode($newData, true);
        $this->createLog($customerData);
        foreach ($customerData as $customer) {
          $this->updateCustomer($customer, $websiteId, $storeId);
      }

  } catch (FileSystemException $e) {
    $this->createLog($e->getMessage());
  }
}
}

private function readCsvRows(string $file, array $header): ?Generator
{
    $handle = fopen($file, 'rb');

    while (!feof($handle)) {
        $data = [];
        $rowData = fgetcsv($handle);
        if ($rowData) {
            foreach ($rowData as $key => $value) {
                $data[$header[$key]] = $value;
            }
            yield $data;
        }
    }

    fclose($handle);
}

private function readCsvHeader(string $file): ?Generator
{
    $handle = fopen($file, 'rb');

    while (!feof($handle)) {
        yield fgetcsv($handle);
    }

    fclose($handle);
}
public function updateCustomer($data, $websiteId, $storeId){
   try {
    if (!empty($data['emailaddress'])) { 
        $isEmailNotExists =$this->accountManagement->isEmailAvailable($data['emailaddress'], $websiteId); 
        if ($isEmailNotExists == true ) {   
            try {  
                $customer   = $this->customerFactory->create();
                $customer->setWebsiteId($websiteId);
                $customer->setStoreId($storeId);
                $customer->setGroupId(1);
                $customer->setEmail($data['emailaddress']); 
                $customer->setFirstname($data['fname']);
                $customer->setLastname($data['lname']);
                $customer->save();
                
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }else{
            $customer   = $this->customerFactory->create();  
            $customer->setWebsiteId($websiteId)->loadByEmail($data['emailaddress']);
            $customer->setWebsiteId($websiteId)->setStoreId($storeId);
            $customer->setGroupId(1)->setFirstname($data['fname'])->setLastname($data['lname'])->setForceConfirmed(true);
            try {
                //update customer
                $this->customerResource->save($customer);
            } catch (AlreadyExistsException $e) {
                throw new AlreadyExistsException(__($e->getMessage()), $e);
            } catch (\Exception $e) {
                throw new \RuntimeException(__($e->getMessage()));
            }
        }
    }            

} catch (\Exception $e) {
    echo $e->getMessage();
}
}
public function createLog($array)
{
    $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/custom.log');
    $logger = new \Zend_Log();
    $logger->addWriter($writer);
    $logger->info('custom data log for customer import');
    $logger->info(print_r($array, true));
}
}
