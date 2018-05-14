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

        $skipped = $added = $error = 0;

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
                $skipped++;
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
                $error++;
                continue;
            }

            $io->writeln(
                sprintf('Adding file "%s" to library.', $file->getRelativePathname())
            );
            $em->persist($song);
            $em->flush($song);
            $added++;
        }

        $io->writeln(
            sprintf('Results for library "%d":', $library->getId())
        );
        $io->writeln(
            sprintf('Added: %d | Skipped: %d | Error: %d', $added, $skipped, $error)
        );
    }

    protected function addMetadataToSong(Song $song, SplFileInfo $file): Song
    {
        $abs = new \Zend_Media_Mpeg_Abs($file->getPathname());
        $song->setLength((int)$abs->getLengthEstimate());
        unset($abs);

        $song = $this->addFileMetadata($song, $file);
        $song = $this->addId3v1Metadata($song, $file);
        $song = $this->addId3v2Metadata($song, $file);


        return $song;
    }

    protected function addFileMetadata(Song $song, SplFileInfo $file): Song
    {
        $song->setTitle($file->getBasename('.' . $file->getExtension()));

        return $song;
    }

    protected function addId3v1Metadata(Song $song, SplFileInfo $file): Song
    {
        try {
            $id3 = new \Zend_Media_Id3v1($file->getPathname());

            $song->setTitle($id3->getTitle());
            $song->setArtist($id3->getArtist());
            $song->setTrackNumber((string)$id3->getTrack());
            $song->setAlbum($id3->getAlbum());
            $song->setYear((int)$id3->getYear());
        } catch (\Exception $exception) {
            // skip
        }

        return $song;
    }


    protected function addId3v2Metadata(Song $song, SplFileInfo $file): Song
    {
        try {
            $id3 = new \Zend_Media_Id3v2($file->getPathname(), ['readonly' => true]);

            /** @var \Zend_Media_Id3_TextFrame $frame */
            foreach ($id3->getFramesByIdentifier("T*") as $frame) {
                $value = $frame->getText();
                $encoding = mb_detect_encoding($value) ?: 'iso-8859-1';

                if ('utf-8' != $encoding) {
                    $value = mb_convert_encoding($value, 'utf-8', $encoding);
                }

                if (!$value) {
                    continue;
                }

                switch (get_class($frame)) {
                    case \Zend_Media_Id3_Frame_Tit2::class:
                        $song->setTitle($value);
                        break;

                    case \Zend_Media_Id3_Frame_Tpe1::class:
                        $song->setArtist($value);
                        break;

                    case \Zend_Media_Id3_Frame_Trck::class:
                        $song->setTrackNumber($value);
                        break;

                    case \Zend_Media_Id3_Frame_Talb::class:
                        $song->setAlbum($value);
                        break;

                    case \Zend_Media_Id3_Frame_Tyer::class:
                        $song->setYear((int)filter_var($value, FILTER_SANITIZE_NUMBER_INT));
                        break;

                    case \Zend_Media_Id3_Frame_Tlen::class:
                        $song->setLength((int)filter_var($value, FILTER_SANITIZE_NUMBER_INT));
                        break;

                    default:
                        break;
                }
            }
        } catch (\Exception $e) {
            // skip
        }

        return $song;
    }
}