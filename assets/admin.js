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

	// Tab switching
	function initTabs() {
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
			} );
		} );
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
					setResult( 'result-llm-edit', renderJson( result.data ), ! result.ok );
				} )
				.catch( function ( err ) {
					setResult( 'result-llm-edit', '<pre>' + ( err.message || String( err ) ) + '</pre>', true );
				} );
		} );
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
		initTabs();
		initInspect();
		initReplaceText();
		initLlmEdit();
		initApplyEdits();
		initAppPassword();
		initRegisterLlm();
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
