<?php

namespace Vivekmehta\Customerimport\Console\Command;

use Exception;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vivekmehta\Customerimport\Model\Customer;
use Symfony\Component\Console\Input\InputArgument;


class CreateCustomers extends Command
{
  private $filesystem;
  private $customer;
  private $state;
  
  public function __construct(
    Filesystem $filesystem,
    Customer $customer,
    State $state
  ) {
    parent::__construct();
    $this->filesystem = $filesystem;
    $this->customer = $customer;
    $this->state = $state;
  }
  public function execute(InputInterface $input, OutputInterface $output): ?int
  {
    try {
      $this->state->setAreaCode(Area::AREA_GLOBAL);
      $import_path = $input->getArgument('import_path');
      $import_file = pathinfo($import_path);
      $fileExtension=$import_file['extension'];
      $mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
      $fixture = $mediaDir->getAbsolutePath() . 'customerimport/'.$import_file['basename'].'';
      $this->customer->install($fixture, $output, $fileExtension);
      echo 'Customer import successfully through CSV file';  
      return Cli::RETURN_SUCCESS;
    } catch (Exception $e) {
      $msg = $e->getMessage();
      $output->writeln("<error>$msg</error>", OutputInterface::OUTPUT_NORMAL);
      return Cli::RETURN_FAILURE;
    }
  }
  public function configure(): void
  {
    $this->setName('customer:import');
    $this->setDescription('Imports customers into Magento from a CSV');
    $this->addArgument('import_path', InputArgument::REQUIRED, 'The path of the import file (ie. ../../pub/customerimport)');
    parent::configure();
  }

}
