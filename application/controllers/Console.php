<?php
declare(strict_types=1);

namespace Controllers;

/**
 * Console — `php cli.php console files`, the codesafe sender's CLI tasks
 * under their name from before the rename (2026-09-24). The cron lines
 * already written keep running; new ones say `php cli.php codesafe files`
 * (Controllers\Codesafe).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Console extends Codesafe
{
}
