/**
 * Live profiler console — tails the per-request profiler stream over SSE.
 * Fed by Ovos\Service\Profiler via a redis stream.
 *
 *   mode="compact"  inline panel — shows the latest request (default)
 *   mode="full"     /profiler/ page — filterable list of all retained requests
 *
 * @author Marcin Gil <mg@ovos.at>
 */

class OvosProfiler extends HTMLElement
{
	connectedCallback()
	{
		this.mode = this.getAttribute('mode') || 'compact';
		this.streamUrl = this.getAttribute('stream-url');
		this.clearUrl = this.getAttribute('clear-url');
		this.limit = this.mode === 'full' ? 200 : 20;
		this.requests = [];
		this.filter = '';
		
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
		}
	}
	
	disconnectedCallback()
	{
		this.source?.close();
	}
	
	connect()
	{
		this.source = new EventSource(this.streamUrl);
		this.source.addEventListener('message', (event) =>
		{
			this.handle(event.data);
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
		const toolbar = document.createElement('div');
		toolbar.className = 'profiler-toolbar';
		
		const filter = document.createElement('input');
		filter.className = 'profiler-filter';
		filter.type = 'search';
		filter.placeholder = 'Filter by method or URL…';
		filter.addEventListener('input', () =>
		{
			this.filter = filter.value.toLowerCase();
			this.applyFilter();
		});
		
		this.count = document.createElement('span');
		this.count.className = 'profiler-count';
		
		const clear = document.createElement('button');
		clear.className = 'profiler-clear';
		clear.type = 'button';
		clear.textContent = 'Clear';
		clear.addEventListener('click', () =>
		{
			this.clearAll();
		});
		
		const close = document.createElement('button');
		close.className = 'profiler-close';
		close.type = 'button';
		close.textContent = '✕ Close';
		close.addEventListener('click', () =>
		{
			window.close();
		});
		
		toolbar.append(filter, this.count, clear, close);
		
		this.list = document.createElement('div');
		this.list.className = 'profiler-list';
		// self-contained card collapse — the /profiler/ page needs no collapse.js
		this.list.addEventListener('click', (event) =>
		{
			const head = event.target.closest('.profiler-request-head');
			if(head)
			{
				head.parentElement.classList.toggle('collapsed');
			}
		});
		
		this.replaceChildren(toolbar, this.list);
	}
	
	// new cards are prepended (newest first) so existing cards — and their
	// expanded/collapsed state — survive incoming requests
	addCard(request)
	{
		const card = this.renderCard(request);
		card.hidden = this.matchesFilter(card.dataset.filter) === false;
		this.list.prepend(card);
		
		while(this.list.children.length > this.limit)
		{
			this.list.lastElementChild.remove();
		}
		
		this.updateCount();
	}
	
	applyFilter()
	{
		for(const card of this.list.children)
		{
			card.hidden = this.matchesFilter(card.dataset.filter) === false;
		}
		
		this.updateCount();
	}
	
	matchesFilter(haystack)
	{
		return this.filter === '' || haystack.includes(this.filter);
	}
	
	updateCount()
	{
		const total = this.list.children.length;
		const visible = [...this.list.children].filter((card) => card.hidden === false).length;
		
		this.count.textContent = `${visible} / ${total} requests`;
	}
	
	// clears the current session's stream server-side, then empties the list;
	// the SSE tail keeps running, so new requests stream back in
	async clearAll()
	{
		if(this.clearUrl)
		{
			try
			{
				const response = await fetch(this.clearUrl, {method: 'POST'});
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
		head.textContent = this.formatSummary(request);
		
		const body = document.createElement('div');
		body.className = 'profiler-request-body';
		body.append(...this.renderTables(request));
		
		card.append(head, body);
		
		return card;
	}
	
	/* shared rendering */
	
	formatSummary(request)
	{
		const parts = [
			`${request.method} ${request.uri}`,
			`Q:${request.queries.length}`,
			`R:${request.redis.length}`,
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
		
		return parts.join(' | ');
	}
	
	renderTables(request)
	{
		const tables = [
			this.renderTable(`Queries (${request.queries.length})`, ['Time', 'Memory'],
				request.queries.map((query) => [query.sql, query.time, query.memory])),
			this.renderTable(`Redis (${request.redis.length})`, ['Time', 'Memory'],
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
			cell.textContent = value ?? '';
			row.append(cell);
		});
		
		return row;
	}
	
	stringify(value)
	{
		return typeof value === 'string' ? value : JSON.stringify(value);
	}
}

customElements.define('ovos-profiler', OvosProfiler);
