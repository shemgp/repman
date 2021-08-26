<?php

declare(strict_types=1);

namespace Buddy\Repman\Service;

use Buddy\Repman\Entity\Organization\Package;
use Buddy\Repman\Service\Dist\Storage;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverterInterface;

final class ReadmeExtractor
{
    private Storage $distStorage;
    private MarkdownConverterInterface $markdownConverter;

    public function __construct(Storage $distStorage)
    {
        $this->distStorage = $distStorage;

        $this->markdownConverter = new CommonMarkConverter(
            [
                'allow_unsafe_links' => false,
            ],
        );
    }

    public function extractReadme(Package $package, Dist $dist): void
    {
        $package->setReadme($this->loadREADME($dist));
    }

    private function loadREADME(Dist $dist): ?string
    {
        $tmpLocalFilename = $this->distStorage->getLocalFileForDist($dist);
        if (null === $tmpLocalFilename->getOrNull()) {
            return null;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($tmpLocalFilename->get());
        if ($result !== true) {
            return null;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $filename = (string) $zip->getNameIndex($i);
                if (preg_match('/^([^\/]+\/)?README.md$/i', $filename) === 1) {
                    return $this->markdownConverter->convertToHtml((string) $zip->getFromIndex($i))->getContent();
                }
            }
        } finally {
            $zip->close();
            @unlink($tmpLocalFilename->get());
        }

        return null;
    }
}
