<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Library;
use App\Entity\Song;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use wapmorgan\Mp3Info\Mp3Info;

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

        $this->scanLibrary($library, (bool)$input->getOption('forceUpdate'), $io);

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

        $flushAfter = 0;
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
                //$io->writeln(
                //    sprintf(
                //        'Skipping existing file "%s".', $file->getRelativePathname()
                //    )
                //);
                continue;
            }

            try {
                $song->setPath($file->getRelativePathname());
                $song->setLibrary($library->getId());
                $currentTime = time();
                $song->setCrdate($currentTime);
                $song->setTstamp($currentTime);

                $song = $this->addMetadataToSong($song, $file);
            } catch (\Exception $exception) {
                $io->error(
                    sprintf(
                        'Cannot get metadata for file "%s". Error: "%s". Skipping file.', $file->getRelativePathname(),
                        $exception->getMessage()
                    )
                );
                continue;
            }


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

    protected function addMetadataToSong(Song $song, SplFileInfo $file): Song
    {
        $mp3Info = new Mp3Info($file->getPathname(), true);

        $song->setTitle($title = $mp3Info->tags2['TIT2'] ?: $mp3Info->tags1['song'] ?: '');
        $song->setArtist($mp3Info->tags2['TPE1'] ?: $mp3Info->tags1['artist'] ?: '');
        $song->setAlbum($mp3Info->tags2['TALB'] ?: $mp3Info->tags1['album'] ?: null);
        $song->setTrackNumber((string)$mp3Info->tags1['track'] ?: null);
        $song->setLength((int)ceil($mp3Info->duration));
        $song->setYear((int)$mp3Info->tags2['TYER'] ?: (int)$mp3Info->tags1['year'] ?: null);

        return $song;
    }
}