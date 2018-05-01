<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Library;
use App\Entity\Song;
use Doctrine\ORM\EntityManager;
use duncan3dc\MetaAudio\Tagger;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

class ScanLibraryCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('firemedia:library:scan')
            ->setDescription('Scan a media library for new songs')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                ''
            )
            ->addOption(
                'forceUpdate',
                null,
                InputOption::VALUE_NONE,
                'Force Update of existing songs'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        /** @var EntityManager $em */
        $em = $this->getContainer()->get('doctrine')->getEntityManager();

        $libraryId = (int)$input->getArgument('id');

        /** @var Library|null $library */
        $library = $em->getRepository('App:Library')->find($libraryId);

        if (null == $library) {
            $io->error(
                sprintf('Library "%d" cannot be found.', $libraryId)
            );
            return 1;
        }

        $this->scanLibrary($library, $input->hasOption('forceUpdate'), $io);

        return 0;
    }

    protected function scanLibrary(Library $library, $forceUpdate = false, SymfonyStyle $io)
    {
        $finder = new Finder();
        $finder->files()->in($library->getPath());
        $finder->files()->name('*.mp3');
        $finder->files()->sortByName();

        /** @var EntityManager $em */
        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        $repository = $em->getRepository('App:Song');

        $tagger = new Tagger();
        $tagger->addDefaultModules();

        $flushAfter = 50;
        $itemPos = 0;

        foreach ($finder as $file) {

            if (!$file->isFile()) {
                continue;
            }

            $song = $repository->findOneBy(
                [
                    'path' => $file->getRelativePathname(),
                    'library' => $library->getId()
                ]
            ) ?: new Song();

            if ($song->getId() && !$forceUpdate) {
                $io->writeln(
                    sprintf(
                        'Skipping existing file "%s".', $file->getRelativePathname()
                    )
                );
                continue;
            }

            try {
                $info = $tagger->open($file->getPathname());
            } catch (\Exception $exception) {
                $io->error(
                    sprintf(
                        'Cannot get metadata for file "%s". Skipping.', $file->getRelativePathname()
                    )
                );
                continue;
            }

            $song->setPath($file->getRelativePathname());
            $song->setLibrary($library->getId());
            $song->setTitle($info->getTitle());
            $song->setArtist($info->getArtist());
            $song->setAlbum($info->getAlbum() ?: null);
            $song->setTrackNumber($info->getTrackNumber() ?: null);
            // todo
            $song->setLength(0);

            $io->writeln(
                sprintf('Adding file "%s" to library.', $file->getRelativePathname())
            );
            $em->persist($song);

            if ($itemPos >= $flushAfter) {
                $em->flush();
                $itemPos = 0;
            } else {
                $itemPos++;
            }
        }

        $em->flush();
    }
}