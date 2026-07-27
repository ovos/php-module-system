/**
 * Live profiler console — tails the profiler streams over SSE.
 * Fed by Ovos\Service\Profiler via redis streams.
 *
 *   mode="compact"  inline panel — shows the latest request (default)
 *   mode="full"     /profiler/ page — two panes: the session's requests and
 *                   the shared CLI stream (runs with live Terminal output)
 *
 * @author Marcin Gil <mg@ovos.at>
 */

class OvosProfiler extends HTMLElement
{
	// Terminal <color> markup vocabulary (Ovos\Terminal\Formatter::$colors)
	static TERMINAL_TAGS = /<(reset|black|gray|darkgray|blue|darkblue|green|darkgreen|cyan|darkcyan|red|darkred|purple|darkpurple|brown|yellow|white)>/;
	
	// box-drawing from Terminal\Table's unicode style — the marker that a
	// message is a table and must not be wrapped (its ascii style is opt-in and
	// indistinguishable from prose punctuation, so it wraps like prose)
	static TABLE_CHARS = /[┌┬┐└┴┘├┼┤│─]/;
	
	// the reverse of Formatter::$colors: raw ANSI SGR params → the tag name we
	// render. Terminal\Table bakes <color> tags to ANSI (terminal-ready output),
	// so a captured table arrives as ANSI, not tags — fold it back.
	static ANSI_TAGS = {
		'0': 'reset', '0;30': 'black', '38;5;246': 'gray', '1;30': 'darkgray',
		'1;34': 'blue', '0;34': 'darkblue', '1;32': 'green', '0;32': 'darkgreen',
		'1;36': 'cyan', '0;36': 'darkcyan', '1;31': 'red', '0;31': 'darkred',
		'1;35': 'purple', '0;35': 'darkpurple', '0;33': 'brown', '1;33': 'yellow',
		'1;37': 'white',
	};
	
	connectedCallback()
	{
		this.mode = this.getAttribute('mode') || 'compact';
		this.streamUrl = this.getAttribute('stream-url');
		this.clearUrl = this.getAttribute('clear-url');
		this.limit = this.mode === 'full' ? 200 : 20;
		this.requests = [];
		this.runs = new Map();
		
		if(this.mode === 'full')
		{
			this.buildFullChrome();
		}
		else
		{
			this.summary = this.querySelector('.profiler-summary');
			this.contents = this.querySelector('.profiler-contents');
		}
		
		if(this.streamUrl)
		{
			this.connect();
			
			if(this.mode === 'full')
			{
				this.connectCli();
			}
		}
	}
	
	disconnectedCallback()
	{
		this.source?.close();
		this.cliSource?.close();
	}
	
	connect()
	{
		this.source = new EventSource(this.streamUrl);
		this.source.addEventListener('message', (event) =>
		{
			this.handle(event.data);
		});
	}
	
	connectCli()
	{
		this.cliSource = new EventSource(`${this.streamUrl}?scope=cli`);
		this.cliSource.addEventListener('message', (event) =>
		{
			this.handleCli(event.data);
		});
	}
	
	handle(data)
	{
		let request;
		try
		{
			request = JSON.parse(data);
		}
		catch(error)
		{
			return;
		}
		
		this.requests.push(request);
		if(this.requests.length > this.limit)
		{
			this.requests.shift();
		}
		
		if(this.mode === 'full')
		{
			this.addCard(request);
		}
		else
		{
			this.renderCompact(request);
		}
	}
	
	/* compact: inline panel */
	
	renderCompact(request)
	{
		this.classList.toggle('has-errors', request.errors.length > 0);
		
		if(this.summary)
		{
			this.summary.textContent = this.formatSummary(request);
		}
		if(this.contents)
		{
			this.contents.replaceChildren(...this.renderTables(request));
		}
	}
	
	/* full: /profiler/ page */
	
	buildFullChrome()
	{
		this.panes = {
			requests: this.buildPane('Requests', 'requests', 'Filter by method or URL…'),
			cli: this.buildPane('CLI', 'cli', 'Filter by command or output…', true),
		};
		this.list = this.panes.requests.list;
		this.count = this.panes.requests.count;
		
		const columns = document.createElement('div');
		columns.className = 'profiler-columns';
		columns.append(this.panes.requests.pane, this.panes.cli.pane);
		
		this.replaceChildren(columns);
	}
	
	// a pane: title + its own filter + count + CLEAR (+ CLOSE on the last
	// pane) above a scrolling card list — the sections are searched
	// independently, each input directly above what it filters
	buildPane(title, scope, placeholder, withClose = false)
	{
		const pane = document.createElement('section');
		pane.className = `profiler-pane profiler-pane-${scope}`;
		
		const head = document.createElement('header');
		head.className = 'profiler-pane-head';
		
		const label = document.createElement('span');
		label.className = 'profiler-pane-title';
		label.textContent = title;
		
		const filter = document.createElement('input');
		filter.className = 'profiler-filter';
		filter.type = 'search';
		filter.placeholder = placeholder;
		filter.addEventListener('input', () =>
		{
			this.panes[scope].filter = filter.value.toLowerCase();
			this.applyFilter(scope);
		});
		
		const count = document.createElement('span');
		count.className = 'profiler-count';
		
		const clear = document.createElement('button');
		clear.className = 'profiler-clear';
		clear.type = 'button';
		clear.textContent = 'Clear';
		clear.addEventListener('click', () =>
		{
			this.clearScope(scope);
		});
		
		head.append(label, filter, count, clear);
		
		if(withClose)
		{
			const close = document.createElement('button');
			close.className = 'profiler-close';
			close.type = 'button';
			close.textContent = '✕ Close';
			close.addEventListener('click', () =>
			{
				window.close();
			});
			head.append(close);
		}
		
		const list = document.createElement('div');
		list.className = 'profiler-list';
		// self-contained card collapse — the /profiler/ page needs no collapse.js
		list.addEventListener('click', (event) =>
		{
			const cardHead = event.target.closest('.profiler-request-head');
			if(cardHead)
			{
				cardHead.parentElement.classList.toggle('collapsed');
			}
		});
		
		pane.append(head, list);
		
		return {pane, list, count, filter: ''};
	}
	
	// new cards are prepended (newest first) so existing cards — and their
	// expanded/collapsed state — survive incoming requests
	addCard(request)
	{
		const card = this.renderCard(request);
		card.hidden = this.matchesFilter(card.dataset.filter, 'requests') === false;
		this.list.prepend(card);
		
		while(this.list.children.length > this.limit)
		{
			this.list.lastElementChild.remove();
		}
		
		this.updateCount();
	}
	
	applyFilter(scope)
	{
		for(const card of this.panes[scope].list.children)
		{
			card.hidden = this.matchesFilter(card.dataset.filter, scope) === false;
		}
		
		if(scope === 'cli')
		{
			this.updateCliCount();
			
			return;
		}
		
		this.updateCount();
	}
	
	matchesFilter(haystack, scope)
	{
		const filter = this.panes[scope].filter;
		
		return filter === '' || haystack.includes(filter);
	}
	
	updateCount()
	{
		const total = this.list.children.length;
		const visible = [...this.list.children].filter((card) => card.hidden === false).length;
		
		// the noun repeats the pane title, so the phone rules hide it rather than
		// let "8 / 8 requests" wrap the head onto a third row
		this.count.textContent = `${visible} / ${total} `;
		this.count.append(this.countNoun('requests'));
	}
	
	updateCliCount()
	{
		const list = this.panes.cli.list;
		const total = list.children.length;
		const visible = [...list.children].filter((card) => card.hidden === false).length;
		
		this.panes.cli.count.textContent = `${visible} / ${total} `;
		this.panes.cli.count.append(this.countNoun('runs'));
	}
	
	countNoun(noun)
	{
		const span = document.createElement('span');
		span.className = 'profiler-count-noun';
		span.textContent = noun;
		
		return span;
	}
	
	// clears the scope's stream server-side, then empties its pane; the SSE
	// tails keep running, so new entries stream back in. The CLI stream is
	// shared — clearing it clears it for every watcher.
	async clearScope(scope)
	{
		if(this.clearUrl)
		{
			try
			{
				const url = scope === 'cli' ? `${this.clearUrl}?scope=cli` : this.clearUrl;
				const response = await fetch(url, {method: 'POST'});
				if(response.ok === false)
				{
					return;
				}
			}
			catch(error)
			{
				return;
			}
		}
		
		if(scope === 'cli')
		{
			this.runs.clear();
			this.panes.cli.list.replaceChildren();
			this.updateCliCount();
			
			return;
		}
		
		this.requests = [];
		this.list.replaceChildren();
		this.updateCount();
	}
	
	renderCard(request)
	{
		const card = document.createElement('div');
		card.className = 'profiler-request collapsed';
		card.dataset.filter = `${request.method} ${request.uri}`.toLowerCase();
		if(request.errors.length)
		{
			card.classList.add('has-errors');
		}
		
		const head = document.createElement('header');
		head.className = 'profiler-request-head';
		// what the row IS (method + path) stays bright; the measurements trail it
		// dimmed, and drop to their own line where the width cannot take both
		head.append(
			this.identity(`${request.method} ${request.uri}`),
			this.metrics(this.formatMetrics(request)),
		);
		
		const body = document.createElement('div');
		body.className = 'profiler-request-body';
		body.append(...this.renderTables(request));
		
		card.append(head, body);
		
		return card;
	}
	
	/* shared rendering */
	
	// the inline panel's one-line header
	formatSummary(request)
	{
		return [`${request.method} ${request.uri}`,
			...this.formatMetrics(request)].join(' | ');
	}
	
	/** the measurements that trail a card's identity */
	formatMetrics(request)
	{
		// the totals, not the retained rows: Q: is how many queries the request
		// ran, and a capped table would under-report it
		const parts = [
			`Q:${request.queries_total ?? request.queries.length}`,
			`R:${request.redis_total ?? request.redis.length}`,
		];
		if(request.streams?.length)
		{
			parts.push(`S:${request.streams.length}`);
		}
		if(request.console.length)
		{
			parts.push(`C:${request.console.length}`);
		}
		if(request.errors.length)
		{
			parts.push(`E:${request.errors.length}`);
		}
		
		const total = request.benchmark?.total;
		if(total)
		{
			parts.push(total.time, total.memory);
		}
		
		return parts;
	}
	
	renderTables(request)
	{
		const tables = [
			this.renderTable(this.heading('Queries', request.queries, request.queries_total),
				['Time', 'Memory'],
				request.queries.map((query) => [query.sql, query.time, query.memory])),
			this.renderTable(this.heading('Redis', request.redis, request.redis_total),
				['Time', 'Memory'],
				request.redis.map((command) => [command.call, command.time, command.memory])),
		];
		
		if(request.streams?.length)
		{
			tables.push(this.renderTable(`Streams (${request.streams.length})`, ['Time', 'Memory'],
				request.streams.map((stream) => [`${stream.method} ${stream.url}`, stream.time, stream.memory])));
		}
		
		tables.push(this.renderTable(`Console (${request.console.length})`, ['', ''],
			request.console.map((message) => [this.stringify(message.message), '', ''])));
		
		if(request.errors.length)
		{
			tables.push(this.renderErrors(request.errors));
		}
		
		return tables;
	}
	
	// errors table with expandable stack traces — clicking an error row
	// toggles the full-width trace row beneath it
	renderErrors(errors)
	{
		const table = this.renderTable(`Errors (${errors.length})`, ['Message', 'Location'], []);
		
		errors.forEach((error) =>
		{
			const row = this.renderRow('cell', [error.type, error.message, `${error.file}:${error.line}`]);
			table.append(row);
			
			if(error.trace)
			{
				const trace = document.createElement('div');
				trace.className = 'row profiler-trace';
				
				const cell = document.createElement('pre');
				cell.className = 'cell profiler-trace-cell';
				cell.textContent = error.trace;
				trace.append(cell);
				table.append(trace);
				
				row.classList.add('profiler-has-trace');
				row.addEventListener('click', () =>
				{
					const open = trace.classList.toggle('open');
					row.classList.toggle('open', open);
				});
			}
		});
		
		return table;
	}
	
	/* CLI pane: start → live messages → finish, correlated by run_id */
	
	handleCli(data)
	{
		let entry;
		try
		{
			entry = JSON.parse(data);
		}
		catch(error)
		{
			return;
		}
		
		if(entry.kind === 'start')
		{
			this.startRun(entry);
		}
		else if(entry.kind === 'message')
		{
			this.appendRunMessage(entry);
		}
		else if(entry.kind === 'finish')
		{
			this.finishRun(entry);
		}
	}
	
	startRun(entry)
	{
		const card = document.createElement('div');
		card.className = 'profiler-request profiler-run running';
		card.dataset.runId = entry.run_id;
		card.dataset.filter = `${entry.command} ${entry.source}`.toLowerCase();
		
		const head = document.createElement('header');
		head.className = 'profiler-request-head';
		
		const source = document.createElement('span');
		source.className = `profiler-run-source profiler-run-source-${entry.source}`;
		source.textContent = entry.source;
		
		const summary = document.createElement('span');
		summary.className = 'profiler-run-summary';
		summary.append(this.identity(entry.command), this.metrics(['running…']));
		
		head.append(source, summary);
		
		const body = document.createElement('div');
		body.className = 'profiler-request-body';
		
		const output = document.createElement('pre');
		output.className = 'profiler-run-output';
		body.append(output);
		
		card.append(head, body);
		card.hidden = this.matchesFilter(card.dataset.filter, 'cli') === false;
		
		this.panes.cli.list.prepend(card);
		this.trimRuns();
		
		this.runs.set(entry.run_id, {
			card,
			summary,
			output,
			command: entry.command,
			haystack: card.dataset.filter,
			tail: '',
		});
		this.updateCliCount();
	}
	
	appendRunMessage(entry)
	{
		const run = this.runs.get(entry.run_id) || this.orphanRun(entry);
		
		// keep tailing only while the reader is already at the bottom — a
		// scrolled-up reader must not be yanked down by new output
		const atBottom = run.output.scrollTop + run.output.clientHeight
			>= run.output.scrollHeight - 24;
		
		// a captured Terminal\Table only lines up at its own width, and the
		// pane wraps (prose messages should) — which turned a table into noise
		// on a phone. Box-drawing means table: give that block its own
		// non-wrapping, horizontally scrollable box.
		const boxed = OvosProfiler.TABLE_CHARS.test(`${entry.message}`);
		let target = run.output;
		if(boxed)
		{
			target = document.createElement('span');
			target.className = 'profiler-run-table';
			run.output.append(target);
		}
		
		this.appendColorized(target, `${entry.message}`, entry.markup === true);
		
		// output content joins the card's search haystack (color-tag names
		// excluded, capped so a chatty run cannot grow the attribute
		// unbounded — the cap drops the oldest output first, the command
		// stays searchable via the base haystack)
		const text = `${entry.message}`.split(OvosProfiler.TERMINAL_TAGS)
			.filter((part, index) => index % 2 === 0)
			.join('');
		run.tail = `${run.tail} ${text.toLowerCase()}`.slice(-16384);
		run.card.dataset.filter = `${run.haystack} ${run.tail}`;
		
		// arriving content can satisfy an active filter — reveal the card
		if(run.card.hidden
			&& this.matchesFilter(run.card.dataset.filter, 'cli'))
		{
			run.card.hidden = false;
			this.updateCliCount();
		}
		
		// messages compose like the terminal (no implicit newline), but
		// line-wise output reads better — close any unterminated line
		if(entry.message.endsWith('\n') === false)
		{
			run.output.append(document.createTextNode('\n'));
		}
		
		// a chatty run must not grow the DOM unbounded
		while(run.output.childNodes.length > 2000)
		{
			run.output.firstChild.remove();
		}
		
		if(atBottom)
		{
			run.output.scrollTop = run.output.scrollHeight;
		}
	}
	
	// message/finish for a run whose start entry was trimmed from the
	// stream (or predates the replay depth) — a card marked as partial
	orphanRun(entry)
	{
		this.startRun({
			run_id: entry.run_id,
			command: '(run already in progress)',
			source: 'cli',
		});
		
		const run = this.runs.get(entry.run_id);
		run.card.classList.add('partial');
		
		return run;
	}
	
	finishRun(entry)
	{
		const run = this.runs.get(entry.run_id) || this.orphanRun(entry);
		
		run.card.classList.remove('running');
		if(entry.errors?.length)
		{
			run.card.classList.add('has-errors');
		}
		
		const parts = [`${entry.duration}s`, this.formatBytes(entry.memory)];
		if(entry.queries)
		{
			parts.push(`Q:${entry.queries.length}`, `R:${entry.redis.length}`);
		}
		if(entry.errors?.length)
		{
			parts.push(`E:${entry.errors.length}`);
		}
		run.summary.replaceChildren(this.identity(run.command), this.metrics(parts));
		
		// the profile below the live output — same renderers as requests
		run.output.parentElement.append(...this.renderTables({
			queries: entry.queries || [],
			redis: entry.redis || [],
			console: entry.console || [],
			errors: entry.errors || [],
		}));
	}
	
	// raw ANSI SGR escapes → the <color> tags appendColorized renders; an
	// unknown code falls back to <reset> so stray escapes never print literally
	static ansiToTags(message)
	{
		return message.replace(/\x1b\[([0-9;]*)m/g, (whole, code) =>
		{
			const tag = OvosProfiler.ANSI_TAGS[code];
			
			return `<${tag ?? 'reset'}>`;
		});
	}
	
	// Terminal <color> markup → spans; markup=false mirrors the terminal
	// and strips the tags instead
	appendColorized(container, message, markup)
	{
		// a captured Terminal\Table (and any raw-ANSI output) arrives with ANSI
		// escapes, not <color> tags — fold them into the same tags and colorize
		// regardless of the markup flag, since ANSI is itself an explicit colour
		if(message.includes('\x1b['))
		{
			message = OvosProfiler.ansiToTags(message);
			markup = true;
		}
		
		const parts = message.split(OvosProfiler.TERMINAL_TAGS);
		
		let color = '';
		parts.forEach((part, index) =>
		{
			if(index % 2 === 1)
			{
				color = part;
				
				return;
			}
			
			if(part === '')
			{
				return;
			}
			
			if(markup && color !== '' && color !== 'reset')
			{
				const span = document.createElement('span');
				span.className = `term-${color}`;
				span.textContent = part;
				container.append(span);
			}
			else
			{
				container.append(document.createTextNode(part));
			}
		});
	}
	
	trimRuns()
	{
		const list = this.panes.cli.list;
		
		while(list.children.length > this.limit)
		{
			this.runs.delete(list.lastElementChild.dataset.runId);
			list.lastElementChild.remove();
		}
	}
	
	formatBytes(bytes)
	{
		if(typeof bytes !== 'number')
		{
			return '';
		}
		
		return `${(bytes / 1048576).toFixed(1)} MB`;
	}
	
	renderTable(title, columns, rows)
	{
		const table = document.createElement('div');
		table.className = 'profiler-table';
		
		table.append(this.renderRow('cell header', [title, ...columns]));
		rows.forEach((cells) =>
		{
			table.append(this.renderRow('cell', cells));
		});
		
		return table;
	}
	
	renderRow(cellClass, cells)
	{
		const row = document.createElement('div');
		row.className = 'row';
		
		cells.forEach((value) =>
		{
			const cell = document.createElement('div');
			cell.className = cellClass;
			// the payload's query and redis rows carry <color> markup from
			// Terminal\Highlighter — the same tags the CLI pane renders. A cell
			// without tags comes back out as a plain text node, so every column
			// can take this path
			this.appendColorized(cell, `${value ?? ''}`, true);
			row.append(cell);
		});
		
		return row;
	}
	
	/**
	 * A table's header: the count, and what the count leaves out. Mirrors
	 * Ovos\Terminal\Highlighter::heading() — a cap that dropped nothing is not
	 * worth mentioning, one that did is, or 20 reads as the whole request.
	 */
	heading(label, rows, total)
	{
		return total > rows.length
			? `${label} (last ${rows.length} of ${total})`
			: `${label} (${rows.length})`;
	}
	
	/** the part of a card head that names the row */
	identity(text)
	{
		const span = document.createElement('span');
		span.className = 'profiler-identity';
		span.textContent = text;
		
		return span;
	}
	
	/** the measurements that trail it, styled as context rather than identity */
	metrics(parts)
	{
		const span = document.createElement('span');
		span.className = 'profiler-metrics';
		span.textContent = parts.join(' | ');
		
		return span;
	}
	
	stringify(value)
	{
		return typeof value === 'string' ? value : JSON.stringify(value);
	}
}

customElements.define('ovos-profiler', OvosProfiler);
