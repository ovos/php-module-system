<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Throwable;

use function dirname;
use function exec;
use function file_put_contents;
use function is_dir;
use function is_file;
use function rename;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

/**
 * Release — the deploy stamp for every project reporting to the console
 *
 *   php cli.php release stamp
 *
 * Writes the checkout's git revision to BASE_DIR/.release so error reports
 * can carry a real deploy label. The consuming side resolves precedence as
 * CONFIGURED WINS: a deployment that supplies console.release knows something
 * a generated stamp cannot, so the file is a fallback, never an override.
 *
 * The stamp PRINTS what it wrote — it exists because a placeholder nobody
 * could see sat in release configs for years, and a stamp you cannot read
 * back would reproduce exactly that. A missing revision logs and exits clean:
 * the stamp is deploy metadata, and metadata must never fail a deploy.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Release extends Controller\Cli
{
	/**
	 * Beside .env — per deployment, machine-written, never committed. The
	 * console repo established the name; the SVN clients carry the same file
	 * at their own roots, so the convention reads identically everywhere.
	 */
	public const string FILE = '.release';
	
	public function stamp(): void
	{
		$revision = $this->revision();
		if($revision === '')
		{
			$this->log('release: no git revision found — nothing written');
			
			return;
		}
		
		$path = BASE_DIR . self::FILE;
		$temp = $path . '.tmp';
		
		// temp + rename: a crash mid-write leaves the previous stamp intact
		// rather than a truncated one, and readers never see a partial line.
		// Caught, not @-suppressed: Events::handleError turns warnings into
		// thrown ErrorExceptions, and the stamp must never fail a deploy —
		// a full disk or an unwritable directory is a log line here.
		try
		{
			$written = file_put_contents($temp, $revision . PHP_EOL) !== false
				&& rename($temp, $path);
			
			if($written === false && is_file($temp))
			{
				unlink($temp);
			}
		}
		catch(Throwable)
		{
			$written = false;
		}
		
		if($written === false)
		{
			$this->log('release: could not write %s', $path);
			
			return;
		}
		
		$this->log('release: %s -> %s', $revision, $path);
	}
	
	/**
	 * The checkout's git revision. git only: every php-library deployment is
	 * a git checkout, and the SVN consumers (leadersnet, westbahn) do not run
	 * php-library — each carries its own stamp tool against its own VCS.
	 *
	 * Both probes matter: console keeps .git one level above BASE_DIR, other
	 * projects may keep it at BASE_DIR itself.
	 */
	protected function revision(): string
	{
		if(is_dir(BASE_DIR . '.git') === false
			&& is_dir(dirname(BASE_DIR) . DIRECTORY_SEPARATOR . '.git') === false)
		{
			return '';
		}
		
		$out = [];
		$code = 1;
		
		// caught, not @-suppressed: an environment where exec cannot run
		// (unable to fork, or disabled — which raises an Error that @ cannot
		// silence anyway) is an unstampable checkout, not a failed deploy
		try
		{
			exec('git rev-parse --short HEAD 2>&1', $out, $code);
		}
		catch(Throwable)
		{
			return '';
		}
		
		if($code !== 0)
		{
			return '';
		}
		
		return trim((string)($out[0] ?? ''));
	}
}
