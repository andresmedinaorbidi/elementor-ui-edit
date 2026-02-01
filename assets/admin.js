/**
 * AI Elementor Sync admin UI.
 * Uses global aiElementorSync: { rest_url, nonce, site_url, ai_service }.
 */
( function () {
	'use strict';

	const config = typeof aiElementorSync !== 'undefined' ? aiElementorSync : {};
	const restUrl = config.rest_url || '';
	const nonce = config.nonce || '';
	const siteUrl = config.site_url || '';

	function getHeaders( jsonBody ) {
		const headers = {
			'X-WP-Nonce': nonce,
		};
		if ( jsonBody ) {
			headers['Content-Type'] = 'application/json';
		}
		return headers;
	}

	function setResult( elId, content, isError ) {
		const el = document.getElementById( elId );
		if ( ! el ) return;
		el.classList.remove( 'loading', 'success', 'error' );
		if ( isError ) el.classList.add( 'error' );
		else el.classList.add( 'success' );
		el.innerHTML = content;
	}

	function setLoading( elId ) {
		const el = document.getElementById( elId );
		if ( ! el ) return;
		el.classList.add( 'loading' );
		el.textContent = 'Loading…';
	}

	function renderJson( data ) {
		return '<pre>' + ( typeof data === 'string' ? data : JSON.stringify( data, null, 2 ) ) + '</pre>';
	}

	// Tab switching (optional onShow callback for tab name)
	function initTabs( onTabShow ) {
		const tabs = document.querySelectorAll( '.ai-elementor-sync-tabs .nav-tab' );
		const panels = document.querySelectorAll( '.ai-elementor-sync-panel' );
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				const tabName = this.getAttribute( 'data-tab' );
				tabs.forEach( function ( t ) {
					t.classList.remove( 'nav-tab-active' );
					t.setAttribute( 'aria-selected', 'false' );
				} );
				panels.forEach( function ( panel ) {
					const id = 'tab-' + tabName;
					const isActive = panel.id === id;
					panel.classList.toggle( 'hidden', ! isActive );
				} );
				this.classList.add( 'nav-tab-active' );
				this.setAttribute( 'aria-selected', 'true' );
				if ( onTabShow && typeof onTabShow === 'function' ) onTabShow( tabName );
			} );
		} );
	}

	function loadSettings() {
		fetch( restUrl + '/settings', {
			method: 'GET',
			headers: getHeaders( false ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				const ai = document.getElementById( 'settings-ai-service-url' );
				const llm = document.getElementById( 'settings-llm-register-url' );
				if ( ai && data.ai_service_url !== undefined ) ai.value = data.ai_service_url || '';
				if ( llm && data.llm_register_url !== undefined ) llm.value = data.llm_register_url || '';
			} )
			.catch( function () {} );
	}

	function loadLog() {
		const container = document.getElementById( 'log-entries' );
		if ( ! container ) return;
		container.textContent = 'Loading…';
		fetch( restUrl + '/log', {
			method: 'GET',
			headers: getHeaders( false ),
			credentials: 'same-origin',
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				const entries = data.entries || [];
				if ( entries.length === 0 ) {
					container.innerHTML = '<p>No log entries yet.</p>';
					return;
				}
				// Newest last in API; show newest first
				const reversed = entries.slice().reverse();
				container.innerHTML = reversed.map( function ( entry ) {
					const ctx = entry.context && Object.keys( entry.context ).length ? '\n' + JSON.stringify( entry.context, null, 2 ) : '';
					const levelClass = 'log-' + ( entry.level || 'info' );
					return '<div class="ai-elementor-sync-log-entry ' + levelClass + '">' +
						'<span class="log-time">' + escapeHtml( entry.time || '' ) + '</span>' +
						'<span class="log-level">[' + escapeHtml( entry.level || 'info' ) + ']</span> ' +
						escapeHtml( entry.message || '' ) +
						( ctx ? '<div class="log-context">' + escapeHtml( ctx ) + '</div>' : '' ) +
						'</div>';
				} ).join( '' );
			} )
			.catch( function ( err ) {
				container.innerHTML = '<p class="error">Failed to load log: ' + escapeHtml( err.message || String( err ) ) + '</p>';
			} );
	}

	function initSettings() {
		const form = document.getElementById( 'form-settings' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const aiUrl = ( document.getElementById( 'settings-ai-service-url' ) || {} ).value?.trim();
			const llmUrl = ( document.getElementById( 'settings-llm-register-url' ) || {} ).value?.trim();
			const resultEl = document.getElementById( 'settings-save-result' );
			if ( resultEl ) resultEl.textContent = 'Saving…';
			fetch( restUrl + '/settings', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( { ai_service_url: aiUrl, llm_register_url: llmUrl } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, data };
					} );
				} )
				.then( function ( result ) {
					if ( resultEl ) {
						resultEl.textContent = result.ok ? 'Saved.' : 'Save failed.';
						resultEl.classList.toggle( 'error', ! result.ok );
					}
				} )
				.catch( function () {
					if ( resultEl ) {
						resultEl.textContent = 'Save failed.';
						resultEl.classList.add( 'error' );
					}
				} );
		} );
	}

	function initLog() {
		const refreshBtn = document.getElementById( 'btn-refresh-log' );
		const clearBtn = document.getElementById( 'btn-clear-log' );
		if ( refreshBtn ) refreshBtn.addEventListener( 'click', loadLog );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				fetch( restUrl + '/clear-log', {
					method: 'POST',
					headers: getHeaders( true ),
					credentials: 'same-origin',
					body: JSON.stringify( {} ),
				} )
					.then( function () { loadLog(); } )
					.catch( function () { loadLog(); } );
			} );
		}
	}

	// Inspect: GET ?url=...
	function initInspect() {
		const form = document.getElementById( 'form-inspect' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const url = ( document.getElementById( 'inspect-url' ) || {} ).value?.trim();
			if ( ! url ) return;
			setLoading( 'result-inspect' );
			const reqUrl = restUrl + '/inspect?url=' + encodeURIComponent( url );
			fetch( reqUrl, {
				method: 'GET',
				headers: getHeaders( false ),
				credentials: 'same-origin',
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, status: res.status, data };
					} );
				} )
				.then( function ( result ) {
					setResult( 'result-inspect', renderJson( result.data ), ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-inspect', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	// Replace text: POST JSON { url, find, replace }
	function initReplaceText() {
		const form = document.getElementById( 'form-replace-text' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const url = ( document.getElementById( 'replace-url' ) || {} ).value?.trim();
			const find = ( document.getElementById( 'replace-find' ) || {} ).value;
			const replace = ( document.getElementById( 'replace-replace' ) || {} ).value;
			if ( ! url || find === undefined || replace === undefined ) return;
			setLoading( 'result-replace-text' );
			fetch( restUrl + '/replace-text', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( { url, find, replace } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, status: res.status, data };
					} );
				} )
				.then( function ( result ) {
					setResult( 'result-replace-text', renderJson( result.data ), ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-replace-text', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	// LLM edit: POST JSON { url, instruction }
	function initLlmEdit() {
		const form = document.getElementById( 'form-llm-edit' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const url = ( document.getElementById( 'llm-url' ) || {} ).value?.trim();
			const instruction = ( document.getElementById( 'llm-instruction' ) || {} ).value;
			if ( ! url || instruction === undefined ) return;
			setLoading( 'result-llm-edit' );
			fetch( restUrl + '/llm-edit', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( { url, instruction } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, status: res.status, data };
					} );
				} )
				.then( function ( result ) {
					const content = renderLlmEditResult( result.data );
					setResult( 'result-llm-edit', content, ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-llm-edit', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	function renderLlmEditResult( data ) {
		if ( ! data ) return renderJson( data );
		const received = data.received_from_llm;
		let html = '';
		if ( received && typeof received === 'object' ) {
			html += '<div class="ai-elementor-sync-llm-received">';
			html += '<h3 class="ai-elementor-sync-result-heading">Received from LLM</h3>';
			html += '<p>Raw edits: <strong>' + escapeHtml( String( received.raw_edits_count ?? '' ) ) + '</strong>, ';
			html += 'Normalized: <strong>' + escapeHtml( String( received.normalized_edits_count ?? '' ) ) + '</strong></p>';
			if ( Array.isArray( received.response_keys ) && received.response_keys.length > 0 ) {
				html += '<p>Response top-level keys: <code>' + escapeHtml( received.response_keys.join( ', ' ) ) + '</code> ';
				html += '(plugin expects <code>edits</code>, or <code>changes</code>, or <code>results</code>)</p>';
			}
			if ( Array.isArray( received.edits ) && received.edits.length > 0 ) {
				html += '<pre class="ai-elementor-sync-edits-pre">' + escapeHtml( JSON.stringify( received.edits, null, 2 ) ) + '</pre>';
			} else {
				html += '<p>No edits to apply (empty or all dropped by normalization). Check the Log tab for the actual response body.</p>';
			}
			html += '</div>';
		}
		if ( Array.isArray( data.failed ) && data.failed.length > 0 ) {
			html += '<div class="ai-elementor-sync-llm-failed">';
			html += '<h3 class="ai-elementor-sync-result-heading">Failed edits (reason)</h3>';
			html += '<ul>';
			data.failed.forEach( function ( entry ) {
				const reason = entry.reason != null ? escapeHtml( String( entry.reason ) ) : '';
				const idPath = [ entry.id, entry.path ].filter( Boolean ).join( ', ') || '—';
				html += '<li><code>' + escapeHtml( idPath ) + '</code> → <strong>' + reason + '</strong>';
				if ( entry.error ) html += ' (' + escapeHtml( entry.error ) + ')';
				html += '</li>';
			} );
			html += '</ul></div>';
		}
		html += '<div class="ai-elementor-sync-llm-full"><h3 class="ai-elementor-sync-result-heading">Full response</h3>';
		html += renderJson( data ) + '</div>';
		return html;
	}

	// Apply edits: POST JSON { url, edits }
	function initApplyEdits() {
		const form = document.getElementById( 'form-apply-edits' );
		if ( ! form ) return;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const url = ( document.getElementById( 'apply-url' ) || {} ).value?.trim();
			let editsRaw = ( document.getElementById( 'apply-edits' ) || {} ).value?.trim();
			if ( ! url || ! editsRaw ) return;
			let edits;
			try {
				edits = JSON.parse( editsRaw );
			} catch ( err ) {
				setResult( 'result-apply-edits', '<pre>Invalid JSON: ' + ( err.message || String( err ) ) + '</pre>', true );
				return;
			}
			if ( ! Array.isArray( edits ) ) {
				setResult( 'result-apply-edits', '<pre>Edits must be a JSON array.</pre>', true );
				return;
			}
			setLoading( 'result-apply-edits' );
			fetch( restUrl + '/apply-edits', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( { url, edits } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, status: res.status, data };
					} );
				} )
				.then( function ( result ) {
					setResult( 'result-apply-edits', renderJson( result.data ), ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-apply-edits', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	// Application password: create button (will be wired in step 3)
	function initAppPassword() {
		const btn = document.getElementById( 'btn-create-app-password' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', function () {
			setLoading( 'app-password-result' );
			fetch( restUrl + '/create-application-password', {
				method: 'POST',
				headers: getHeaders( true ),
				credentials: 'same-origin',
				body: JSON.stringify( {} ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, status: res.status, data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.data && result.data.password ) {
						const password = result.data.password;
						const username = result.data.username || '';
						const content =
							'<p><strong>Save this password now. It will not be shown again.</strong></p>' +
							'<div class="password-display">' + escapeHtml( password ) + '</div>' +
							'<p><button type="button" class="button" id="btn-copy-password">Copy</button></p>' +
							'<p class="copy-hint">Use with username <code>' + escapeHtml( username ) + '</code> for Basic auth (e.g. in your LLM app).</p>';
						setResult( 'app-password-result', content, false );
						document.getElementById( 'app-password-result' ).setAttribute( 'data-password', password );
						document.getElementById( 'app-password-result' ).setAttribute( 'data-username', username );
						const registerSection = document.getElementById( 'app-password-register' );
						if ( registerSection ) registerSection.classList.remove( 'hidden' );
						const copyBtn = document.getElementById( 'btn-copy-password' );
						if ( copyBtn ) {
							copyBtn.addEventListener( 'click', function () {
								navigator.clipboard.writeText( password ).then( function () {
									copyBtn.textContent = 'Copied!';
								} );
							} );
						}
					} else {
						const msg = ( result.data && result.data.message ) || ( result.data && result.data.code ) || 'Failed to create application password.';
						setResult( 'app-password-result', '<pre>' + escapeHtml( typeof msg === 'string' ? msg : JSON.stringify( result.data ) ) + '</pre>', true );
						if ( result.status === 503 ) {
							const unavail = document.getElementById( 'app-password-unavailable' );
							const btn = document.getElementById( 'btn-create-app-password' );
							if ( unavail ) unavail.classList.remove( 'hidden' );
							if ( btn ) btn.style.display = 'none';
						}
					}
				} )
				.catch( function ( err ) {
					setResult( 'app-password-result', '<pre>' + escapeHtml( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
	}

	function escapeHtml( str ) {
		if ( str == null ) return '';
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	// Register with LLM app: reads password/username from app-password-result data attributes
	function initRegisterLlm() {
		const regBtn = document.getElementById( 'btn-register-llm' );
		if ( ! regBtn ) return;
		regBtn.addEventListener( 'click', function () {
			const resultEl = document.getElementById( 'app-password-result' );
			const password = resultEl ? resultEl.getAttribute( 'data-password' ) : null;
			const username = resultEl ? resultEl.getAttribute( 'data-username' ) : '';
			if ( ! password ) {
				const out = document.getElementById( 'result-register-llm' );
				if ( out ) {
					out.classList.add( 'error' );
					out.innerHTML = '<p>Create an application password first, then click Register.</p>';
				}
				return;
			}
			const registerUrl = ( document.getElementById( 'llm-register-url' ) || {} ).value?.trim();
			if ( ! registerUrl ) {
				const out = document.getElementById( 'result-register-llm' );
				if ( out ) {
					out.classList.add( 'error' );
					out.innerHTML = '<p>Enter the LLM app register URL.</p>';
				}
				return;
			}
			const out = document.getElementById( 'result-register-llm' );
			if ( out ) {
				out.textContent = 'Sending…';
				out.classList.remove( 'success', 'error' );
			}
			const payload = { site_url: siteUrl, username: username, application_password: password };
			fetch( registerUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload ),
			} )
				.then( function ( res ) {
					return res.text().then( function ( text ) {
						return { ok: res.ok, status: res.status, text };
					} );
				} )
				.then( function ( r ) {
					if ( out ) {
						out.classList.add( r.ok ? 'success' : 'error' );
						out.innerHTML = r.ok ? '<pre>Registered successfully.</pre>' : '<pre>' + escapeHtml( r.text || 'Error ' + r.status ) + '</pre>';
					}
				} )
				.catch( function ( err ) {
					if ( out ) {
						out.classList.add( 'error' );
						out.innerHTML = '<pre>' + escapeHtml( err.message || String( err ) ) + '</pre>';
					}
				} );
		} );
	}

	function init() {
		initTabs( function ( tabName ) {
			if ( tabName === 'settings' ) loadSettings();
			if ( tabName === 'log' ) loadLog();
		} );
		initInspect();
		initReplaceText();
		initLlmEdit();
		initApplyEdits();
		initAppPassword();
		initRegisterLlm();
		initSettings();
		initLog();
		// Pre-fill LLM register URL from option if set
		const llmRegisterInput = document.getElementById( 'llm-register-url' );
		if ( llmRegisterInput && config.llm_register_url ) {
			llmRegisterInput.value = config.llm_register_url;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
