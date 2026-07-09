<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\DuplicateCanonicalRenameSelector;
use MagicSunday\Renamer\Service\DuplicateCanonicalSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies canonical rename selection for the legacy duplicate pipeline.
 *
 * The selector preserves the old idempotency and Live Photo priority rules that
 * decide which rename gets the clean base name before duplicate suffixes are
 * assigned.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DuplicateCanonicalRenameSelector::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(DuplicateCanonicalSelection::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(FileList::class)]
#[UsesClass(Rename::class)]
#[UsesClass(RenameList::class)]
final class DuplicateCanonicalRenameSelectorTest extends TestCase
{
    /**
     * Verifies that a source file already carrying the canonical basename wins
     * over another rename that merely points at the same canonical target path.
     */
    #[Test]
    public function selectPrefersExactCanonicalBasename(): void
    {
        $selector   = new DuplicateCanonicalRenameSelector();
        $directory  = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'selector';
        $targetPath = $directory . DIRECTORY_SEPARATOR . '2024-01-15.jpg';

        $candidateA = new Rename(
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . 'other.jpg'),
            new SplFileInfo($targetPath),
        );
        $candidateB = new Rename(
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . '2024-01-15.jpg'),
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . '2024-01-15.jpg'),
        );

        $duplicate = new FileDuplicate();
        $duplicate
            ->setTarget(new SplFileInfo($targetPath))
            ->addRename($candidateA)
            ->addRename($candidateB);

        $selection = $selector->select($duplicate, []);

        self::assertSame($candidateB, $selection->canonicalRename);
        self::assertTrue($selection->canonicalHasExactName);
        self::assertTrue($selection->canonicalNeedsPromotion);
    }

    /**
     * Verifies that when no exact-basename candidate exists, a file with a Live
     * Photo content identifier wins canonical selection over a plain duplicate.
     */
    #[Test]
    public function selectPrefersLivePhotoContentIdentifierWhenNoExactNameExists(): void
    {
        $selector   = new DuplicateCanonicalRenameSelector();
        $directory  = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'selector';
        $targetPath = $directory . DIRECTORY_SEPARATOR . '2024-01-15.jpg';

        $plainRename = new Rename(
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . 'plain.jpg'),
            new SplFileInfo($targetPath),
        );
        $livePhotoRename = new Rename(
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . 'lp.jpg'),
            new SplFileInfo($targetPath),
        );

        $duplicate = new FileDuplicate();
        $duplicate
            ->setTarget(new SplFileInfo($targetPath))
            ->addRename($plainRename)
            ->addRename($livePhotoRename);

        $selection = $selector->select($duplicate, [
            $livePhotoRename->getSource()->getPathname() => 'content-id',
        ]);

        self::assertSame($livePhotoRename, $selection->canonicalRename);
        self::assertFalse($selection->canonicalHasExactName);
        self::assertTrue($selection->canonicalNeedsPromotion);
    }

    /**
     * Verifies that without an exact basename match or Live Photo content ID,
     * the selector keeps the first qualifying rename as canonical and leaves
     * promotion enabled for the unsuffixed base name.
     */
    #[Test]
    public function selectKeepsFirstQualifyingRenameAsFallback(): void
    {
        $selector   = new DuplicateCanonicalRenameSelector();
        $directory  = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'selector';
        $targetPath = $directory . DIRECTORY_SEPARATOR . '2024-01-15.jpg';

        $firstRename = new Rename(
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . 'candidate-a.jpg'),
            new SplFileInfo($targetPath),
        );
        $secondRename = new Rename(
            new SplFileInfo($directory . DIRECTORY_SEPARATOR . 'candidate-b.jpg'),
            new SplFileInfo($targetPath),
        );

        $duplicate = new FileDuplicate();
        $duplicate
            ->setTarget(new SplFileInfo($targetPath))
            ->addRename($firstRename)
            ->addRename($secondRename);

        $selection = $selector->select($duplicate, []);

        self::assertSame($firstRename, $selection->canonicalRename);
        self::assertFalse($selection->canonicalHasExactName);
        self::assertTrue($selection->canonicalNeedsPromotion);
    }
}
