<?php declare(strict_types=1);

namespace App\Command;


use App\Entity\Library;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class CreateLibraryCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('firemedia:library:add')
            ->setDescription('Adds a new media library')
            ->addOption(
                'name',
                null,
                InputOption::VALUE_OPTIONAL
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_OPTIONAL
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $io = new SymfonyStyle($input, $output);

        $name = $input->getOption('name');
        if (null === $name) {
            $question = new Question('Enter the name of the library: ', null);
            $name = $helper->ask($input, $output, $question);
        }

        $path = $input->getOption('path');
        if (null === $path) {
            $question = new Question('Enter the path of the library: ', null);
            $path = $helper->ask($input, $output, $question);
        }

        $fileSystem = new Filesystem();
        if (!$fileSystem->exists($path)) {
            $io->error(
                sprintf('"%s" must be a directory.', $path)
            );
            return 1;
        }

        $library = new Library();
        $library->setName($name);
        $library->setPath($path);

        /** @var EntityManager $em */
        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        $em->persist($library);
        $em->flush($library);

        return 0;
    }
}