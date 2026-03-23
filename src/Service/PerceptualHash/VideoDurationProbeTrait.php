<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

use function is_numeric;
use function trim;

/**
 * Shared helper for probing video duration via ffprobe.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
trait VideoDurationProbeTrait
{
    /**
     * Probes the video duration via ffprobe.
     */
    private function probeVideoDuration(SplFileInfo $file): ?float
    {
        $process = new Process([
            $this->ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $file->getPathname(),
        ]);
        $process->setTimeout(10.0);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '' || !is_numeric($output)) {
            return null;
        }

        $duration = (float) $output;

        return $duration > 0.0 ? $duration : null;
    }
}
