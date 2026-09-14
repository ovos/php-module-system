<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Service\Console\Sender;
use Ovos\Service\Console\Untracked;

use function count;
use function sprintf;

/**
 * Console — the console sender's CLI tasks (ovos/console, docs/SENDER.md §7)
 *
 *   php cli.php console files            # the untracked pass, as a cron line
 *   php cli.php console files manual     # the same, marked as a person's run
 *
 * The working-copy pass asks the working copy at or above BASE_DIR what the
 * repository did not ship — untracked files (`git ls-files --others
 * --exclude-standard`), tracked files that differ or are gone (`git diff
 * --name-status HEAD`), `svn status` for both — and posts the console's
 * integrity-scan report: the one detector that sees a dropped file BEFORE
 * anything runs it, and the place a payload written INTO a file shows. Read-only,
 * CLI only, and honest about what it cannot know: no working copy, a closed
 * `proc_open`, a non-zero exit (git's safe.directory refusal included) all
 * print as "cannot know" and send nothing. An EMPTY answer is still sent —
 * that is how a finding the console holds goes GONE.
 *
 *   0,15,30,45 * * * *  cd /var/www/site/project && php cli.php console files
 *
 * Needs `- Console\Sender` under system.services.cli, console.url + key, and
 * files_enabled on the console project (a 403 says so).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Console extends Controller\Cli
{
	public function files(
		?string $mode = null,
	): void
	{
		$sender = $this->app->getServices()->consoleSender ?? null;
		if($sender instanceof Sender === false)
		{
			$this->log('%s', 'console files: the sender is not a CLI service — list "- Console\Sender" under system.services.cli');
			
			return;
		}
		if($sender->isEnabled() === false)
		{
			$this->log('%s', 'console files: the sender is off (console.enabled, url, key) — nothing asked');
			
			return;
		}
		
		$found = Untracked::root(BASE_DIR);
		if($found === null)
		{
			$this->log('%s', sprintf('console files: no git or svn working copy at or above %s — nothing to ask', BASE_DIR));
			
			return;
		}
		
		$report = $sender->untrackedReport([
			'mode' => $mode === Untracked::MODE_MANUAL ? Untracked::MODE_MANUAL : Untracked::MODE_BACKGROUND,
		]);
		if($report === null)
		{
			$this->log('%s', sprintf('console files: %s at %s did not answer (proc_open closed, or a non-zero exit) — cannot know, nothing sent',
				$found['vcs'], $found['root']));
			
			return;
		}
		
		$counts = $report['scan']['counts'];
		$area = $report['areas'][Untracked::AREA];
		$this->log('%s', sprintf('console files: %s working copy %s — %d untracked, %d modified, %d missing in %d director%s: %d urgent, %d high, %d info; %d listed',
			$found['vcs'], $found['root'], $area['foreign'], $area['modified'], $area['missing'], $report['scan']['dirs'],
			$report['scan']['dirs'] === 1 ? 'y' : 'ies',
			$counts['urgent'], $counts['high'], $counts['info'], count($report['findings'])));
		
		$code = $sender->reportFiles($report);
		$this->log('%s', match(true)
		{
			$code === 202 => 'console files: accepted by the console (202)',
			$code === 403 => 'console files: refused (403) — file reports are off for this project on the console (files_enabled)',
			$code === 401 => 'console files: refused (401) — the project key is not accepted',
			$code === 0 => 'console files: no answer from the console',
			default => sprintf('console files: refused (%d)', $code),
		});
	}
}
