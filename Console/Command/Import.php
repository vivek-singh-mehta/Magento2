<?php
declare(strict_types=1);

namespace Vivekmehta\Customerimport\Console\Command;

use Generator;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Customer\Api\AccountManagementInterface;

/**
 * Customer Import
 */
class Import extends Command
{
    /**
     *
     */
    private const PROFILE_NAME_ARGUMENT = "profile-name";
    /**
     *
     */
    private const CSV_FILE_ARGUMENT = "csv-file-name";
    /**
     * @var DirectoryList
     */
    private $directoryList;
    /**
     * @var File
     */
    private $file;
    /**
     * @var Csv
     */
    private $csv;
    /**
     * @var CustomerFactory
     */
    private $customerFactory;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;
    /**
     * @var \Magento\Customer\Api\Data\CustomerInterface
     */
    private $customer;
    /**
     * @var CustomerInterfaceFactory
     */
    private $customerInterfaceFactory;
    /**
     * @var Encryptor
     */
    private $encryptor;

    /**
     * @var string[]
     */
    private $supportedFileFormat = ['csv','json'];
    /**
     * @var Json
     */
    private $json;
    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    private $ioFile;
    /**
     * @var AccountManagementInterface
     */
    private $customerAccountManagement;
    /**
     * @var \Magento\Customer\Model\ResourceModel\Customer
     */
        protected $customerResource;
    /**
     * @param DirectoryList $directoryList
     * @param File $file
     * @param Csv $csv
     * @param \Magento\Customer\Model\CustomerFactory    $customerFactory
     * @param CustomerInterfaceFactory $customerInterfaceFactory
     * @param StoreManagerInterface $storeManager
     * @param CustomerRepositoryInterface $customerRepository
     * @param Encryptor $encryptor
     * @param Json $json
     * @param \Magento\Framework\Filesystem\Io\File $ioFile
     * @param AccountManagementInterface $customerAccountManagement
     * @param \Magento\Customer\Model\ResourceModel\Customer $customerResource
     */
    public function __construct(
        DirectoryList $directoryList,
        File $file,
        Csv $csv,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        CustomerInterfaceFactory $customerInterfaceFactory,
        StoreManagerInterface $storeManager,
        CustomerRepositoryInterface $customerRepository,
        Encryptor $encryptor,
        Json $json,
        \Magento\Framework\Filesystem\Io\File $ioFile,
        AccountManagementInterface $customerAccountManagement,
        \Magento\Customer\Model\ResourceModel\Customer $customerResource,
    ) {
        $this->directoryList = $directoryList;
        $this->file = $file;
        $this->csv = $csv;
        $this->customerFactory  = $customerFactory;
        $this->storeManager = $storeManager;
        $this->customerRepository = $customerRepository;
        $this->customerInterfaceFactory = $customerInterfaceFactory;
        $this->encryptor = $encryptor;
        $this->json = $json;
        $this->ioFile = $ioFile;
        $this->accountManagement = $customerAccountManagement;
        $this->customerResource = $customerResource;
        parent::__construct();
    }

    /**
     * @inheritdoc
     * @throws \Exception
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $profileName = $input->getArgument(self::PROFILE_NAME_ARGUMENT);
        $csvFileName = $input->getArgument(self::CSV_FILE_ARGUMENT);
        $rootDirectory = $this->directoryList->getRoot();
        $csvFile = $rootDirectory . "/pub/media/customerimport/" . $csvFileName;
        $pathInfo = $this->ioFile->getPathInfo($csvFile);
        $fileExtension = $pathInfo['extension'];
        if (!in_array($fileExtension, $this->supportedFileFormat)) {
            $output->writeln("<error>Invalid File Format. Only csv or json file format is supported</error>");
            return Cli::RETURN_FAILURE;
        }
        if ($this->file->isExists($csvFile)) {
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            $storeId =  (int) $this->storeManager->getStore()->getId();
            if ($fileExtension === 'csv') {
                $this->csv->setDelimiter(",");
                $data = $this->csv->getData($csvFile);
                if (!empty($data)) {
                        $header = $this->readCsvHeader($csvFile)->current();
                        $row = $this->readCsvRows($csvFile, $header);
                        $row->next();

                    while ($row->valid()) {
                        $data = $row->current(); 
                        $this->updateCustomer($data, $websiteId, $storeId);
                        $row->next();


                    }
                
                }
            } elseif ($fileExtension === 'json') {
                $json = $this->file->fileGetContents($csvFile);
                $data = $this->json->unserialize($json);
                foreach ($data as $value) {
                    try {
                        $this->customerRepository->get($value['emailaddress'], $websiteId);
                        continue;
                    } catch (NoSuchEntityException|LocalizedException $e) {
                    }
                    $customer = $this->customerInterfaceFactory->create();
                    $customer->setWebsiteId($websiteId);
                    $password = $this->getRandomPassword();
                    $passwordHash = $this->encryptor->getHash($password, true);
                    $customer->setWebsiteId($websiteId);
                    $customer->setFirstname($value['fname']);
                    $customer->setLastname($value['lname']);
                    $customer->setEmail($value['emailaddress']);
                    $this->customerRepository->save($customer, $passwordHash);
                }
            }
            $output->writeln("<info>Customer Import Successfully Completed ");//phpcs:ignore
            return Cli::RETURN_SUCCESS;
        } else {
            $output->writeln("<error>Given file not exist insider var/import directory.</error>");
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName("customer:import");
        $this->setDescription("Import customer using csv and json");
        $this->setDefinition([
            new InputArgument(self::PROFILE_NAME_ARGUMENT, InputArgument::REQUIRED, "Profile Name"),
            new InputArgument(self::CSV_FILE_ARGUMENT, InputArgument::REQUIRED, "CSV/JSON FILE")
        ]);
        parent::configure();
    }

    /**
     * Generate password
     *
     * @return false|string
     */
    private function getRandomPassword()
    {
        $length = 8;
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$&";
        return substr(str_shuffle($chars), 0, $length);
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
public function updateCustomer($data, $websiteId, $storeId)
{
       if (!empty($data['emailaddress'])) { 
        $isEmailNotExists =$this->accountManagement->isEmailAvailable($data['emailaddress'], $websiteId); 
        if ($isEmailNotExists == true ) {   
            try {  
                $customer = $this->customerInterfaceFactory->create();
                    $customer->setWebsiteId($websiteId);
                    $password = $this->getRandomPassword();
                    $passwordHash = $this->encryptor->getHash($password, true);
                    $customer->setWebsiteId($websiteId);
                    $customer->setFirstname($data['fname']);
                    $customer->setLastname($data['lname']);
                    $customer->setEmail($data['emailaddress']);
                    $this->customerRepository->save($customer, $passwordHash);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }else{ //echo $data['emailaddress'];
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
}
}
