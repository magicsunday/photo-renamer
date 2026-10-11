<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MagicSunday\Renamer\Application;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;

use function fflush;
use function fgets;
use function fwrite;
use function getenv;
use function is_string;

require __DIR__ . '/../../.build/vendor/autoload.php';

$workspace = getenv('RENAMER_JOURNAL_TEST_DIRECTORY');

if (!is_string($workspace) || ($workspace === '')) {
    throw new RuntimeException('The interrupted mutation worker requires its synthetic workspace.');
}

$filesystem = new Filesystem();
$guard      = FileSystemServiceFactory::createSourceIdentityGuard();
$journal    = FileSystemServiceFactory::createMutationJournal($workspace . '/state');
$mover      = new RuntimeFileMoveExecutor(new NullProgressReporter(), $filesystem, new RuntimeCollisionPathAllocator(), $guard, $journal);
$command    = new Command('rename:exif');
$command->addArgument('source', InputArgument::REQUIRED);
$command->setCode(static function () use ($workspace, $journal, $mover): int {
    $still           = $workspace . '/media/camera.jpg';
    $companion       = $workspace . '/media/camera.mov';
    $stillTarget     = $workspace . '/media/capture.jpg';
    $companionTarget = $workspace . '/media/capture.mov';
    $journal->planLivePhoto(new ExecutionGroup('synthetic pair', true, $still, [
        new ExecutionItem($still, $stillTarget, ExecutionItemType::Canonical, true, false, 'synthetic pair'),
        new ExecutionItem($companion, $companionTarget, ExecutionItemType::Companion, true, false, 'synthetic pair'),
    ]));
    $occupied = [$still => true, $companion => true];
    $mover->moveFileByPath($still, $stillTarget, $occupied, false);

    // The receipt has returned before the handshake. The parent can now kill
    // the worker without relying on filesystem timings or arbitrary sleeps.
    fwrite(STDOUT, "FIRST_MOVE_RECORDED\n");
    fflush(STDOUT);
    fgets(STDIN);
    $mover->moveFileByPath($companion, $companionTarget, $occupied, false);

    return Command::SUCCESS;
});
$application = new Application([$command], new BatchRunLock($filesystem, $workspace . '/state'), $guard, $journal);
$application->setAutoExit(false);
exit($application->run(new ArrayInput(['command' => 'rename:exif', 'source' => $workspace . '/media']), new NullOutput()));
