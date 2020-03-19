<?php

namespace EMS\CoreBundle\Command;

use Elasticsearch\Client;
use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Exception\CantBeFinalizedException;
use EMS\CoreBundle\Exception\NotLockedException;
use EMS\CoreBundle\Service\ContentTypeService;
use EMS\CoreBundle\Service\DataService;
use EMS\CoreBundle\Service\ImportService;
use Monolog\Logger;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class ImportCommand extends EmsCommand
{
    /** @var ImportService */
    private $importService;
    /** @var ContentTypeService */
    private $contentTypeService;
    /** @var DataService */
    private $dataService;

    public function __construct(Logger $logger, Client $client, ContentTypeService $contentTypeService, ImportService $importService, DataService $dataService)
    {
        $this->contentTypeService = $contentTypeService;
        $this->importService = $importService;
        $this->dataService = $dataService;
        parent::__construct($logger, $client);
    }
    
    protected function configure()
    {
        $this
            ->setName('ems:document:import')
            ->setDescription('Import a bunch of json files from a zip file')
            ->addArgument(
                'contentTypeName',
                InputArgument::REQUIRED,
                'Content type name to import into'
            )
            ->addArgument(
                'archive',
                InputArgument::REQUIRED,
                'Zip file containing the json files'
            )
            ->addOption(
                'bulkSize',
                null,
                InputOption::VALUE_OPTIONAL,
                'Size of the elasticsearch bulk request',
                500
            )
            ->addOption(
                'raw',
                null,
                InputOption::VALUE_NONE,
                'The content will be imported as is. Without any field validation, data stripping or field protection'
            )
            ->addOption(
                'dont-sign-data',
                null,
                InputOption::VALUE_NONE,
                'The content will not be signed during the import process'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Also treat document in draft mode'
            )
            ->addOption(
                'dont-finalize',
                null,
                InputOption::VALUE_NONE,
                'Don\'t finalize document'
            )
            ->addOption(
                'businessKey',
                null,
                InputOption::VALUE_NONE,
                'Try to identify documents by their business keys'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $contentTypeName = $input->getArgument('contentTypeName');
        $archiveFilename = $input->getArgument('archive');
        $bulkSize = $input->getOption('bulkSize');
        $rawImport = $input->getOption('raw');
        $signData = !$input->getOption('dont-sign-data');
        $finalize = !$input->getOption('dont-finalize');
        $force = $input->getOption('force');
        $replaceBusinessKey = $input->getOption('businessKey');


        $contentType = $this->contentTypeService->getByName($contentTypeName);
        if (!$contentType instanceof ContentType) {
            $output->writeln(sprintf('<error>Content type %s not found</error>', $contentTypeName));
            return -1;
        }

        if ($contentType->getDirty()) {
            $output->writeln(sprintf('<error>Content type %s is dirty. Please clean it first.</error>', $contentTypeName));
            return -1;
        }

        if (!file_exists($archiveFilename)) {
            $output->writeln(sprintf('<error>Archive file %s does not exist.</error>', $archiveFilename));
            return -1;
        }
        
        $output->writeln(sprintf('Start importing %s from %s', $contentType->getPluralName(), $archiveFilename));

        $zip = new ZipArchive();
        if ($zip->open($archiveFilename) !== true) {
            $output->writeln(sprintf('<error>Archive file %s can not be open.</error>', $archiveFilename));
            return -1;
        }

        $workingDirectory = tempnam(sys_get_temp_dir(), 'ImportCommand');
        $filesystem = new Filesystem();
        $filesystem->remove($workingDirectory);
        $filesystem->mkdir($workingDirectory);

        $zip->extractTo($workingDirectory);
        $zip->close();

        $finder = new Finder();
        $finder->files()->in($workingDirectory)->name('*.json');
        $progress = new ProgressBar($output, $finder->count());
        $progress->start();
        $importer = $this->importService->initDocumentImporter($contentType, 'SYSTEM_IMPORT', $rawImport, $signData, true, $bulkSize, $finalize, $force);

        $loopIndex = 0;
        foreach ($finder as $file) {
            $rawData = json_decode(file_get_contents($file), true);
            $ouuid = basename($file->getFilename(), '.json');
            if ($replaceBusinessKey) {
                $dataLink = explode(':', $this->dataService->getDataLink($contentType->getName(), $ouuid));
                $ouuid = array_pop($dataLink);
            }

            $document = $this->dataService->hitFromBusinessIdToDataLink($contentType, $ouuid, $rawData);

            try {
                $importer->importDocument($document->getOuuid(), $document->getSource());
            } catch (NotLockedException $e) {
                $output->writeln("<error>'.$e.'</error>");
            } catch (CantBeFinalizedException $e) {
                $output->writeln("<error>'.$e.'</error>");
            }

            ++$loopIndex;
            if ($loopIndex % $bulkSize == 0) {
                $importer->clearAndSend();
                $loopIndex = 0;
            }
            $progress->advance();
        }
        $importer->clearAndSend(true);
        $progress->finish();
        $output->writeln("");
        $output->writeln("Import done");
    }
}