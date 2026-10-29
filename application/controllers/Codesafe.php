<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Service\Codesafe\Sender;
use Ovos\Service\Codesafe\Untracked;

use function count;
use function sprintf;

/**
 * Codesafe — the codesafe sender's CLI tasks (ovos/codesafe, docs/SENDER.md §7)
 *
 *   php cli.php codesafe integrity           # the untracked pass, as a cron line
 *   php cli.php codesafe integrity manual    # the same, marked as a person's run
 *
 * The working-copy pass asks the working copy at or above BASE_DIR what the
 * repository did not ship — untracked files (`git ls-files --others
 * --exclude-standard`), tracked files that differ or are gone (`git diff
 * --name-status HEAD`), `svn status` for both — and posts codesafe's
 * integrity-scan report: the one detector that sees a dropped file BEFORE
 * anything runs it, and the place a payload written INTO a file shows. Read-only,
 * CLI only, and honest about what it cannot know: no working copy, a closed
 * `proc_open`, a non-zero exit (git's safe.directory refusal included) all
 * print as "cannot know" and send nothing. An EMPTY answer is still sent —
 * that is how a finding codesafe holds goes GONE.
 *
 *   0,15,30,45 * * * *  cd /var/www/site/project && php cli.php codesafe integrity
 *
 * Needs `- Codesafe\Sender` under system.services.cli, codesafe.url + key, and
 * integrity_enabled on the codesafe project (a 403 says so). `codesafe files`,
 * the command's older name, runs the same pass: a crontab line written for it
 * keeps working.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Codesafe extends Controller\Cli
{
	public function integrity(
		?string $mode = null,
	): void
	{
		$sender = $this->app->getServices()->codesafeSender ?? null;
		if($sender instanceof Sender === false)
		{
			$this->log('%s', 'codesafe integrity: the sender is not a CLI service — list "- Codesafe\Sender" under system.services.cli');
			
			return;
		}
		if($sender->isEnabled() === false)
		{
			$this->log('%s', 'codesafe integrity: the sender is off (codesafe.enabled, url, key) — nothing asked');
			
			return;
		}
		
		$found = Untracked::root(BASE_DIR);
		if($found === null)
		{
			$this->log('%s', sprintf('codesafe integrity: no git or svn working copy at or above %s — nothing to ask', BASE_DIR));
			
			return;
		}
		
		$report = $sender->untrackedReport([
			'mode' => $mode === Untracked::MODE_MANUAL ? Untracked::MODE_MANUAL : Untracked::MODE_BACKGROUND,
		]);
		if($report === null)
		{
			$this->log('%s', sprintf('codesafe integrity: %s at %s did not answer (proc_open closed, or a non-zero exit) — cannot know, nothing sent',
				$found['vcs'], $found['root']));
			
			return;
		}
		
		$counts = $report['scan']['counts'];
		$area = $report['areas'][Untracked::AREA];
		$this->log('%s', sprintf('codesafe integrity: %s working copy %s — %d untracked, %d modified, %d missing in %d director%s: %d urgent, %d high, %d info; %d listed',
			$found['vcs'], $found['root'], $area['foreign'], $area['modified'], $area['missing'], $report['scan']['dirs'],
			$report['scan']['dirs'] === 1 ? 'y' : 'ies',
			$counts['urgent'], $counts['high'], $counts['info'], count($report['findings'])));
		
		$code = $sender->reportFiles($report);
		$this->log('%s', match(true)
		{
			$code === 202 => 'codesafe integrity: accepted by codesafe (202)',
			$code === 403 => 'codesafe integrity: refused (403) — integrity reports are off for this project on codesafe (integrity_enabled)',
			$code === 401 => 'codesafe integrity: refused (401) — the project key is not accepted',
			$code === 0 => 'codesafe integrity: no answer from codesafe',
			default => sprintf('codesafe integrity: refused (%d)', $code),
		});
	}
	
	/** the command's older name, for a crontab line written before `integrity` */
	public function files(
		?string $mode = null,
	): void
	{
		$this->integrity($mode);
	}
}
